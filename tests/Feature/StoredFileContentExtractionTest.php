<?php

use App\Enums\StoredFileContentFailure;
use App\Enums\StoredFileContentStatus;
use App\Enums\TeamRole;
use App\Exceptions\StoredFileContentExtractionException;
use App\Jobs\ExtractStoredFileContent;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\StoredFileContent;
use App\Models\Team;
use App\Models\User;
use App\Support\StoredFileContentExtractor;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function storedFileContentFile(
    string $extension,
    string $mimeType,
    string $bytes = 'source bytes',
): StoredFile {
    $path = 'projects/test/'.Str::uuid().'.'.$extension;
    Storage::disk('local')->put($path, $bytes);
    $creator = User::factory()->make();
    $creator->save();
    $team = Team::factory()->create(['slug' => 'content-'.Str::lower((string) Str::ulid())]);
    $team->members()->attach($creator, ['role' => TeamRole::Owner->value]);
    $creator->update(['current_team_id' => $team->id]);
    $project = Project::factory()
        ->for($team)
        ->for($creator, 'creator')
        ->create();

    return StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($creator, 'uploader')
        ->create([
            'path' => $path,
            'original_name' => "report.{$extension}",
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ]));
}

function pendingStoredFileContent(StoredFile $storedFile, string $extractor): StoredFileContent
{
    return StoredFileContent::factory()->create([
        'stored_file_id' => $storedFile->id,
        'source_checksum' => $storedFile->checksum,
        'status' => StoredFileContentStatus::Pending,
        'extractor' => $extractor,
    ]);
}

test('stored file creation prepares supported content and queues extraction', function () {
    config()->set('stored-file-content.enabled', true);
    Queue::fake();

    $supported = StoredFile::factory()->create([
        'original_name' => 'report.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    $unsupported = StoredFile::factory()->create([
        'original_name' => 'report.xlsm',
        'mime_type' => 'application/vnd.ms-excel.sheet.macroenabled.12',
    ]);

    expect($supported->contentSearchIndex()->sole()->status)->toBe(StoredFileContentStatus::Pending)
        ->and($supported->contentSearchIndex()->sole()->content)->toBeNull()
        ->and($unsupported->contentSearchIndex()->sole()->status)->toBe(StoredFileContentStatus::Unsupported);
    Queue::assertPushed(
        ExtractStoredFileContent::class,
        fn (ExtractStoredFileContent $job): bool => $job->storedFileId === $supported->id
            && $job->sourceChecksum === $supported->checksum
            && $job->sourceDisk === $supported->disk
            && $job->sourcePath === $supported->path
            && $job->queue === null
            && $job->afterCommit === true,
    );
    Queue::assertPushed(ExtractStoredFileContent::class, 1);
});

test('markitdown extraction normalizes and truncates successful output', function () {
    Storage::fake('local');
    config()->set('stored-file-content.max_indexed_bytes', 18);
    $storedFile = storedFileContentFile(
        'docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
    $stagedPath = null;

    Process::fake(function (PendingProcess $process) use (&$stagedPath): object {
        $command = $process->command;
        $stagedPath = $command[1];
        file_put_contents($command[3], "  Quarterly\n\n revenue\tgrew substantially  ");

        return Process::result();
    });

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Ready)
        ->and($result->extractor)->toBe('markitdown')
        ->and($result->content)->toBe('Quarterly revenue')
        ->and($result->contentBytes)->toBe(17)
        ->and($result->isTruncated)->toBeTrue()
        ->and($stagedPath)->not->toBeNull()
        ->and(dirname((string) $stagedPath))->not->toBeDirectory();
});

test('markitdown extraction excludes embedded image data from searchable content', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile(
        'docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    Process::fake(function (PendingProcess $process): object {
        file_put_contents(
            $process->command[3],
            'Visible text ![](data:image/jpeg;base64,/9j/4AAQ) '
                .'![Revenue chart](data:image/png;base64,iVBORw0KGgo=) after.',
        );

        return Process::result();
    });

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Ready)
        ->and($result->content)->toBe('Visible text Revenue chart after.')
        ->and($result->content)->not->toContain('data:image');
});

test('encrypted modern office documents publish no content without starting markitdown', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile(
        'docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1encrypted package",
    );
    Process::fake();

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Protected)
        ->and($result->failure)->toBe(StoredFileContentFailure::EncryptedDocument)
        ->and($result->content)->toBeNull();
    Process::assertNothingRan();
});

test('encrypted PDFs publish no content without starting markitdown', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile(
        'pdf',
        'application/pdf',
        "%PDF-1.7\ntrailer << /Encrypt 2 0 R >>\n%%EOF",
    );
    Process::fake();

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Protected)
        ->and($result->failure)->toBe(StoredFileContentFailure::EncryptedDocument)
        ->and($result->content)->toBeNull();
    Process::assertNothingRan();
});

test('extractor discards output that exceeds the capture limit', function () {
    Storage::fake('local');
    config()->set('stored-file-content.max_output_bytes', 5);
    $storedFile = storedFileContentFile('pdf', 'application/pdf');
    Process::fake(['*' => Process::result(output: '123456')]);

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Failed)
        ->and($result->failure)->toBe(StoredFileContentFailure::OutputTooLarge)
        ->and($result->content)->toBeNull();
});

test('content extraction job claims and publishes only its current source', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile('pdf', 'application/pdf');
    pendingStoredFileContent($storedFile, 'markitdown');

    Process::fake(function (PendingProcess $process): object {
        file_put_contents($process->command[3], 'Searchable project milestone');

        return Process::result();
    });

    (new ExtractStoredFileContent(
        $storedFile->id,
        $storedFile->checksum,
        $storedFile->disk,
        $storedFile->path,
    ))->handle(app(StoredFileContentExtractor::class));

    $content = $storedFile->contentSearchIndex()->sole();

    expect($content->status)->toBe(StoredFileContentStatus::Ready)
        ->and($content->content)->toBe('Searchable project milestone')
        ->and($content->processing_started_at)->toBeNull()
        ->and($content->extracted_at)->not->toBeNull();
});

test('retryable extraction failures reset the matching row to pending', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile('pdf', 'application/pdf');
    pendingStoredFileContent($storedFile, 'markitdown');
    Process::fake(fn (): RuntimeException => new RuntimeException('process unavailable'));
    $job = new ExtractStoredFileContent(
        $storedFile->id,
        $storedFile->checksum,
        $storedFile->disk,
        $storedFile->path,
    );

    expect(fn () => $job->handle(app(StoredFileContentExtractor::class)))
        ->toThrow(StoredFileContentExtractionException::class);

    $content = $storedFile->contentSearchIndex()->sole();

    expect($content->status)->toBe(StoredFileContentStatus::Pending)
        ->and($content->processing_started_at)->toBeNull()
        ->and($content->content)->toBeNull();
});

test('a stale job cannot publish over replacement content', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile('pdf', 'application/pdf', 'old bytes');
    pendingStoredFileContent($storedFile, 'markitdown');
    $oldChecksum = $storedFile->checksum;
    $oldPath = $storedFile->path;

    Process::fake(function (PendingProcess $process) use ($storedFile): object {
        $newPath = 'projects/test/replacement.pdf';
        $newBytes = 'new bytes';
        Storage::disk('local')->put($newPath, $newBytes);
        StoredFile::query()->whereKey($storedFile->id)->update([
            'path' => $newPath,
            'checksum' => hash('sha256', $newBytes),
            'size' => strlen($newBytes),
        ]);
        StoredFileContent::query()->whereKey($storedFile->id)->update([
            'source_checksum' => hash('sha256', $newBytes),
            'status' => StoredFileContentStatus::Pending->value,
            'content' => null,
        ]);
        file_put_contents($process->command[3], 'stale extracted text');

        return Process::result();
    });

    (new ExtractStoredFileContent(
        $storedFile->id,
        $oldChecksum,
        $storedFile->disk,
        $oldPath,
    ))->handle(app(StoredFileContentExtractor::class));

    $content = $storedFile->contentSearchIndex()->sole();

    expect($content->status)->toBe(StoredFileContentStatus::Pending)
        ->and($content->source_checksum)->toBe($storedFile->refresh()->checksum)
        ->and($content->content)->toBeNull();
});

test('a stale terminal failure cannot mark an equal-checksum replacement failed', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile('pdf', 'application/pdf');
    pendingStoredFileContent($storedFile, 'markitdown');
    $oldPath = $storedFile->path;
    $newPath = 'projects/test/equal-checksum-replacement.pdf';
    Storage::disk('local')->put($newPath, 'source bytes');
    $storedFile->update(['path' => $newPath]);

    (new ExtractStoredFileContent(
        $storedFile->id,
        $storedFile->checksum,
        $storedFile->disk,
        $oldPath,
    ))->failed(new RuntimeException('worker failed'));

    expect($storedFile->contentSearchIndex()->sole()->status)
        ->toBe(StoredFileContentStatus::Pending);
});

test('replacement clears old searchable content before queuing the new source', function () {
    Storage::fake('local');
    Queue::fake();
    config()->set('stored-file-content.enabled', true);
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $oldPath = 'projects/test/report.pdf';
    Storage::disk('local')->put($oldPath, 'old bytes');
    $storedFile = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => $oldPath,
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
            'checksum' => hash('sha256', 'old bytes'),
        ]));
    StoredFileContent::factory()->create([
        'stored_file_id' => $storedFile->id,
        'source_checksum' => $storedFile->checksum,
        'status' => StoredFileContentStatus::Ready,
        'extractor' => 'markitdown',
        'content' => 'old searchable text',
        'content_bytes' => 19,
    ]);

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $storedFile]), [
            'file' => UploadedFile::fake()->create('new-report.pdf', 10, 'application/pdf'),
        ])
        ->assertRedirect();

    $storedFile->refresh();
    $content = $storedFile->contentSearchIndex()->sole();

    expect($content->source_checksum)->toBe($storedFile->checksum)
        ->and($content->status)->toBe(StoredFileContentStatus::Pending)
        ->and($content->content)->toBeNull()
        ->and($content->content_bytes)->toBe(0);
    Queue::assertPushed(
        ExtractStoredFileContent::class,
        fn (ExtractStoredFileContent $job): bool => $job->storedFileId === $storedFile->id
            && $job->sourceChecksum === $storedFile->checksum
            && $job->sourcePath === $storedFile->path,
    );
});

test('content backfill is idempotent and can retry failed rows', function () {
    Storage::fake('local');
    Queue::fake();
    config()->set('stored-file-content.enabled', true);
    $storedFile = storedFileContentFile(
        'docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );

    $this->artisan('stored-files:backfill-content')
        ->assertExitCode(Command::SUCCESS);
    $this->artisan('stored-files:backfill-content')
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(ExtractStoredFileContent::class, 1);

    $storedFile->contentSearchIndex()->update([
        'status' => StoredFileContentStatus::Failed,
        'failure_code' => StoredFileContentFailure::ProcessFailed,
    ]);

    $this->artisan('stored-files:backfill-content', ['--retry-failed' => true])
        ->assertExitCode(Command::SUCCESS);

    expect($storedFile->contentSearchIndex()->sole()->status)
        ->toBe(StoredFileContentStatus::Pending);
    Queue::assertPushed(ExtractStoredFileContent::class, 2);
});

test('content rows cascade when their stored file is deleted', function () {
    Storage::fake('local');
    $storedFile = storedFileContentFile('pdf', 'application/pdf');
    pendingStoredFileContent($storedFile, 'markitdown');

    $storedFile->delete();

    expect(StoredFileContent::query()->whereKey($storedFile->id)->exists())->toBeFalse();
});

test('content extraction queue budgets accommodate overlap releases', function () {
    $job = new ExtractStoredFileContent(1, str_repeat('a', 64), 'local', 'projects/test/report.pdf');
    $middleware = $job->middleware()[0];

    expect($job->timeout)->toBe((int) config('stored-file-content.job_timeout'))
        ->and($job->tries)->toBe(4)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBeGreaterThan(0)
        ->and($middleware->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and($job->tries * $middleware->releaseAfter)->toBeGreaterThan($middleware->expiresAfter)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.beanstalkd.retry_after'))->toBeGreaterThan($job->timeout);
});
