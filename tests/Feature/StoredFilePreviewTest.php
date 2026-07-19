<?php

use App\Enums\TeamRole;
use App\Jobs\GenerateStoredFilePreview;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\StoredFilePreviewGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function storedFilePreviewProject(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    return compact('owner', 'member', 'outsider', 'team', 'project');
}

test('stored file creation schedules previews only for supported extensions', function () {
    Queue::fake();

    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();

    foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'ods', 'odt', 'ppt', 'pptx', 'odp'] as $extension) {
        $file = StoredFile::factory()->for($project)->create([
            'original_name' => "source.{$extension}",
        ]);

        expect($file->preview_status)->toBe('pending')
            ->and($file->preview_path)->toBeNull();
    }

    $unsupported = StoredFile::factory()->for($project)->create([
        'original_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    expect($unsupported->preview_status)->toBeNull()
        ->and($unsupported->preview_path)->toBeNull();

    Queue::assertPushed(GenerateStoredFilePreview::class, 11);
});

test('legacy preview backfill claims supported rows once and leaves unsupported rows untouched', function () {
    Queue::fake();

    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $supported = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/legacy-report.pdf',
            'original_name' => 'legacy-report.pdf',
            'preview_path' => null,
            'preview_status' => null,
        ]));
    $unsupported = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/legacy-photo.jpg',
            'original_name' => 'legacy-photo.jpg',
            'preview_path' => null,
            'preview_status' => null,
        ]));

    $this->artisan('stored-files:backfill-previews', ['--chunk' => 1])
        ->assertExitCode(Command::SUCCESS);

    expect($supported->refresh()->preview_status)->toBe('pending')
        ->and($supported->preview_path)->toBeNull()
        ->and($unsupported->refresh()->preview_status)->toBeNull()
        ->and($unsupported->preview_path)->toBeNull();
    Queue::assertPushed(
        GenerateStoredFilePreview::class,
        fn (GenerateStoredFilePreview $job): bool => $job->storedFileId === $supported->id
            && $job->sourcePath === $supported->path
            && $job->afterCommit === true,
    );

    $this->artisan('stored-files:backfill-previews', ['--chunk' => 1])
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(GenerateStoredFilePreview::class, 1);
});

test('preview backfill retries failed rows without duplicating pending rows', function () {
    Queue::fake();

    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $failed = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/failed-report.pdf',
            'original_name' => 'failed-report.pdf',
            'preview_path' => null,
            'preview_status' => 'failed',
        ]));
    StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/pending-report.pdf',
            'original_name' => 'pending-report.pdf',
            'preview_path' => null,
            'preview_status' => 'pending',
        ]));

    $this->artisan('stored-files:backfill-previews')
        ->assertExitCode(Command::SUCCESS);

    expect($failed->refresh()->preview_status)->toBe('pending');
    Queue::assertPushed(
        GenerateStoredFilePreview::class,
        1,
    );
});

test('http uploads use the central stored file preview scheduling seam', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('report.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])
        ->assertRedirect();

    $storedFile = StoredFile::query()->sole();

    expect($storedFile->preview_status)->toBe('pending');
    Queue::assertPushed(
        GenerateStoredFilePreview::class,
        fn (GenerateStoredFilePreview $job): bool => $job->storedFileId === $storedFile->id
            && $job->sourcePath === $storedFile->path
            && $job->afterCommit === true,
    );
});

test('a post-commit queue failure keeps the committed row and original blob', function () {
    Storage::fake('local');
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/queue-down.pdf';
    Storage::disk('local')->put($sourcePath, 'original bytes');

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    $this->app->instance(Dispatcher::class, $bus);

    $file = DB::transaction(fn (): StoredFile => $project->storedFiles()->create([
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'path' => $sourcePath,
        'original_name' => 'queue-down.pdf',
        'mime_type' => 'application/pdf',
        'size' => 14,
    ]));

    expect($file->refresh())->not->toBeNull()
        ->and($file->preview_status)->toBe('failed');
    Storage::disk('local')->assertExists($sourcePath);
});

test('a serialized retry can reclaim processing and finish the same source', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/retry.pdf';
    Storage::disk('local')->put($sourcePath, 'pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => 'retry.pdf',
        'mime_type' => 'application/pdf',
        'preview_status' => 'processing',
    ]);
    $file->update(['preview_status' => 'processing']);

    Process::fake(function (PendingProcess $process): object {
        $command = $process->command;
        $binary = $command[0] ?? null;

        if ($binary === config('services.poppler.path')) {
            file_put_contents($command[array_key_last($command)].'.png', 'png bytes');
        }

        if ($binary === config('services.imagemagick.path')) {
            file_put_contents($command[array_key_last($command)], 'webp bytes');
        }

        return Process::result();
    });

    (new GenerateStoredFilePreview($file->id, $sourcePath))->handle(app(StoredFilePreviewGenerator::class));

    expect($file->refresh()->preview_status)->toBe('ready')
        ->and($file->preview_path)->toBe(StoredFilePreviewGenerator::webpPath($file->id, $sourcePath));
    Storage::disk('local')->assertExists(StoredFilePreviewGenerator::webpPath($file->id, $sourcePath));
});

test('the preview generator converts page one to a source-versioned webp and marks it ready', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.pdf', 'pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $binary = $command[0] ?? null;

        if ($binary === config('services.poppler.path')) {
            file_put_contents($command[array_key_last($command)].'.png', 'png bytes');
        }

        if ($binary === config('services.imagemagick.path')) {
            file_put_contents($command[array_key_last($command)], 'webp bytes');
        }

        return Process::result();
    });

    (new GenerateStoredFilePreview($file->id, $file->path))->handle(app(StoredFilePreviewGenerator::class));

    $file->refresh();

    expect($file->preview_status)->toBe('ready')
        ->and($file->preview_path)->toBe(StoredFilePreviewGenerator::webpPath($file->id, $file->path));
    Storage::disk('local')->assertExists(StoredFilePreviewGenerator::webpPath($file->id, $file->path));
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === config('services.poppler.path'));
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === config('services.imagemagick.path'));
});

test('office generation caches one pdf before rasterizing its first page', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.docx', 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => 'report.docx',
    ]);

    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $binary = $command[0] ?? null;

        if ($binary === config('services.libreoffice.path')) {
            $outputDirectory = $command[array_search('--outdir', $command, true) + 1];
            $inputPath = $command[array_key_last($command)];
            file_put_contents($outputDirectory.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', 'cached pdf bytes');
        }

        if ($binary === config('services.poppler.path')) {
            file_put_contents($command[array_key_last($command)].'.png', 'png bytes');
        }

        if ($binary === config('services.imagemagick.path')) {
            file_put_contents($command[array_key_last($command)], 'webp bytes');
        }

        return Process::result();
    });

    (new GenerateStoredFilePreview($file->id, $file->path))->handle(app(StoredFilePreviewGenerator::class));

    expect($file->refresh()->preview_status)->toBe('ready');
    expect(Storage::disk('local')->get(StoredFilePreviewGenerator::cachedPdfPath($file->id, $file->path)))->toBe('cached pdf bytes');
    Storage::disk('local')->assertExists(StoredFilePreviewGenerator::webpPath($file->id, $file->path));
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === config('services.libreoffice.path'));
});

test('additional office formats use the existing pdf fallback preview', function (string $extension, string $mimeType) {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = "projects/test/report.{$extension}";
    Storage::disk('local')->put($sourcePath, 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => "report.{$extension}",
        'mime_type' => $mimeType,
    ]);

    Process::fake(function (PendingProcess $process): object {
        $command = $process->command;

        if (($command[0] ?? null) === config('services.libreoffice.path')) {
            $outputDirectory = $command[array_search('--outdir', $command, true) + 1];
            $inputPath = $command[array_key_last($command)];
            file_put_contents($outputDirectory.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', 'converted pdf content');
        }

        return Process::result();
    });

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertContent('converted pdf content');
})->with([
    ['xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12'],
    ['ods', 'application/vnd.oasis.opendocument.spreadsheet'],
    ['odt', 'application/vnd.oasis.opendocument.text'],
    ['odp', 'application/vnd.oasis.opendocument.presentation'],
]);

test('preview conversion failure marks the file failed and keeps the original blob', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.pdf', 'original bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
    ]);
    Process::fake(fn () => Process::result(errorOutput: 'conversion failed', exitCode: 1));

    (new GenerateStoredFilePreview($file->id, $file->path))->handle(app(StoredFilePreviewGenerator::class));

    expect($file->refresh()->preview_status)->toBe('failed')
        ->and($file->preview_path)->toBeNull();
    Storage::disk('local')->assertExists('projects/test/report.pdf');
});

test('a duplicate job cannot remove artifacts from a ready same-source generation', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/report.pdf';
    Storage::disk('local')->put($sourcePath, 'pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => 'report.pdf',
    ]);
    $previewPath = StoredFilePreviewGenerator::webpPath($file->id, $sourcePath);
    $cachedPdfPath = StoredFilePreviewGenerator::cachedPdfPath($file->id, $sourcePath);
    $file->update(['preview_path' => $previewPath, 'preview_status' => 'ready']);
    Storage::disk('local')->put($previewPath, 'webp bytes');
    Storage::disk('local')->put($cachedPdfPath, 'pdf bytes');

    (new GenerateStoredFilePreview($file->id, $sourcePath))->handle(app(StoredFilePreviewGenerator::class));

    expect($file->refresh()->preview_status)->toBe('ready')
        ->and($file->preview_path)->toBe($previewPath);
    Storage::disk('local')->assertExists($previewPath);
    Storage::disk('local')->assertExists($cachedPdfPath);
});

test('a failed callback cannot remove artifacts from a ready same-source generation', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/report.pdf';
    Storage::disk('local')->put($sourcePath, 'pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => 'report.pdf',
    ]);
    $previewPath = StoredFilePreviewGenerator::webpPath($file->id, $sourcePath);
    $cachedPdfPath = StoredFilePreviewGenerator::cachedPdfPath($file->id, $sourcePath);
    $file->update(['preview_path' => $previewPath, 'preview_status' => 'ready']);
    Storage::disk('local')->put($previewPath, 'webp bytes');
    Storage::disk('local')->put($cachedPdfPath, 'pdf bytes');

    (new GenerateStoredFilePreview($file->id, $sourcePath))->failed(new RuntimeException('duplicate failure'));

    expect($file->refresh()->preview_status)->toBe('ready')
        ->and($file->preview_path)->toBe($previewPath);
    Storage::disk('local')->assertExists($previewPath);
    Storage::disk('local')->assertExists($cachedPdfPath);
});

test('a stale job during replacement cannot publish or update the new generation', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $oldPath = 'projects/test/old-report.docx';
    $newPath = 'projects/test/new-report.docx';
    Storage::disk('local')->put($oldPath, 'old office bytes');
    Storage::disk('local')->put($newPath, 'new office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $oldPath,
        'original_name' => 'report.docx',
    ]);

    $generator = new class($file, $newPath) extends StoredFilePreviewGenerator
    {
        public function __construct(
            private StoredFile $file,
            private string $newPath,
        ) {}

        public function generate(StoredFile $storedFile): string
        {
            StoredFile::query()->whereKey($this->file->id)->update([
                'path' => $this->newPath,
                'preview_path' => null,
                'preview_status' => 'pending',
            ]);
            Storage::disk($storedFile->disk)->put(
                StoredFilePreviewGenerator::cachedPdfPath($storedFile->id, $storedFile->path),
                'old pdf bytes',
            );
            Storage::disk($storedFile->disk)->put(
                StoredFilePreviewGenerator::webpPath($storedFile->id, $storedFile->path),
                'old webp bytes',
            );

            return StoredFilePreviewGenerator::webpPath($storedFile->id, $storedFile->path);
        }
    };

    (new GenerateStoredFilePreview($file->id, $oldPath))->handle($generator);

    expect($file->refresh()->path)->toBe($newPath)
        ->and($file->preview_status)->toBe('pending')
        ->and($file->preview_path)->toBeNull();
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::cachedPdfPath($file->id, $oldPath));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::webpPath($file->id, $oldPath));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::cachedPdfPath($file->id, $newPath));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::webpPath($file->id, $newPath));
});

test('a stale job during deletion cannot recreate preview artifacts', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/report.pdf';
    Storage::disk('local')->put($sourcePath, 'pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => 'report.pdf',
    ]);
    $file->update(['preview_status' => 'deleting', 'preview_path' => null]);
    Storage::disk('local')->put(StoredFilePreviewGenerator::cachedPdfPath($file->id, $sourcePath), 'pdf bytes');
    Storage::disk('local')->put(StoredFilePreviewGenerator::webpPath($file->id, $sourcePath), 'webp bytes');
    Process::fake();

    (new GenerateStoredFilePreview($file->id, $sourcePath))->handle(app(StoredFilePreviewGenerator::class));

    expect($file->refresh()->preview_status)->toBe('deleting');
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::cachedPdfPath($file->id, $sourcePath));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::webpPath($file->id, $sourcePath));
    Process::assertNothingRan();
});

test('a stale failure cannot mark the replacement generation failed', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $oldPath = 'projects/test/old-report.pdf';
    $newPath = 'projects/test/new-report.pdf';
    Storage::disk('local')->put($oldPath, 'old pdf bytes');
    Storage::disk('local')->put($newPath, 'new pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $oldPath,
        'original_name' => 'report.pdf',
    ]);

    $job = new GenerateStoredFilePreview($file->id, $oldPath);
    $generator = new class($file, $newPath) extends StoredFilePreviewGenerator
    {
        public function __construct(
            private StoredFile $file,
            private string $newPath,
        ) {}

        public function generate(StoredFile $storedFile): string
        {
            StoredFile::query()->whereKey($this->file->id)->update([
                'path' => $this->newPath,
                'preview_path' => null,
                'preview_status' => 'pending',
            ]);

            throw new RuntimeException('conversion failed');
        }
    };
    $job->handle($generator);
    $job->failed(new RuntimeException('worker failure'));

    expect($file->refresh()->path)->toBe($newPath)
        ->and($file->preview_status)->toBe('pending')
        ->and($file->preview_path)->toBeNull();
});

test('preview queue budgets leave the retry window longer than the job timeout', function () {
    $job = new GenerateStoredFilePreview(1, 'projects/test/report.pdf');
    $middleware = $job->middleware()[0];

    expect($job->timeout)->toBe(240)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->key)->toBe('1-'.StoredFilePreviewGenerator::sourceHash('projects/test/report.pdf'))
        ->and($middleware->releaseAfter)->toBeGreaterThan(0)
        ->and($middleware->expiresAfter)->toBeGreaterThan($job->timeout)
        ->and($job->tries * $middleware->releaseAfter)->toBeGreaterThan($middleware->expiresAfter)
        ->and(config('services.libreoffice.timeout'))->toBe(120)
        ->and(config('services.poppler.timeout'))->toBe(45)
        ->and(config('services.imagemagick.timeout'))->toBe(45)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.beanstalkd.retry_after'))->toBeGreaterThan($job->timeout);
});

test('ready thumbnails are private, inline, project-scoped, and authenticated', function () {
    Storage::fake('local');
    ['owner' => $owner, 'member' => $member, 'outsider' => $outsider, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.pdf',
    ]);
    $file->update([
        'preview_path' => StoredFilePreviewGenerator::webpPath($file->id, $file->path),
        'preview_status' => 'ready',
    ]);
    Storage::disk('local')->put($file->preview_path, 'webp bytes');

    $response = $this->actingAs($member)
        ->get(route('projects.files.thumbnail', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'image/webp')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent('webp bytes');

    expect($response->headers->get('cache-control'))
        ->toContain('private')
        ->toContain('no-store')
        ->and($response->headers->get('content-disposition'))->toBe('inline');

    $this->actingAs($outsider)
        ->get(route('projects.files.thumbnail', [$team, $project, $file]))
        ->assertForbidden();
});

test('file detail exposes preview status and only exposes an existing ready image URL', function () {
    Storage::fake('local');
    ['owner' => $owner, 'member' => $member, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.pdf',
    ]);
    $file->update([
        'preview_path' => StoredFilePreviewGenerator::webpPath($file->id, $file->path),
        'preview_status' => 'ready',
    ]);
    Storage::disk('local')->put($file->preview_path, 'webp bytes');

    $this->actingAs($member)
        ->get(route('projects.files.show', [$team, $project, $file]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('file.previewStatus', 'ready')
            ->where('file.previewImageUrl', route('projects.files.thumbnail', [$team, $project, $file], absolute: false)));
});

test('replacement removes old derived previews, resets state, and schedules regeneration', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.pdf', 'old bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
    ]);
    $oldPreviewPath = StoredFilePreviewGenerator::webpPath($file->id, $file->path);
    $file->update(['preview_path' => $oldPreviewPath, 'preview_status' => 'ready']);
    Storage::disk('local')->put($oldPreviewPath, 'old webp');
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, $file->path),
        'old pdf',
    );
    Storage::disk('local')->put(StoredFilePreviewGenerator::legacyPdfPath($file->id), 'legacy pdf');
    Storage::disk('local')->put(StoredFilePreviewGenerator::legacyWebpPath($file->id), 'legacy webp');
    Queue::fake();

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create('new-report.pdf', 10, 'application/pdf'),
        ])
        ->assertRedirect();

    $file->refresh();

    expect($file->preview_status)->toBe('pending')
        ->and($file->preview_path)->toBeNull();
    Storage::disk('local')->assertMissing('projects/test/report.pdf');
    Storage::disk('local')->assertMissing($oldPreviewPath);
    Storage::disk('local')->assertMissing(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, 'projects/test/report.pdf'),
    );
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::legacyPdfPath($file->id));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::legacyWebpPath($file->id));
    Queue::assertPushed(
        GenerateStoredFilePreview::class,
        fn (GenerateStoredFilePreview $job): bool => $job->storedFileId === $file->id
            && $job->sourcePath === $file->path
            && $job->afterCommit === true,
    );
});

test('deleting a stored file removes the cached pdf and webp', function () {
    Storage::fake('local');
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.pdf', 'original bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
    ]);
    $file->update([
        'preview_path' => StoredFilePreviewGenerator::webpPath($file->id, $file->path),
    ]);
    $file->update(['preview_status' => 'ready']);
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, $file->path),
        'pdf bytes',
    );
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::webpPath($file->id, $file->path),
        'webp bytes',
    );
    Storage::disk('local')->put(StoredFilePreviewGenerator::legacyPdfPath($file->id), 'legacy pdf bytes');
    Storage::disk('local')->put(StoredFilePreviewGenerator::legacyWebpPath($file->id), 'legacy webp bytes');

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $file]))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('projects/test/report.pdf');
    Storage::disk('local')->assertMissing(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, $file->path),
    );
    Storage::disk('local')->assertMissing(
        StoredFilePreviewGenerator::webpPath($file->id, $file->path),
    );
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::legacyPdfPath($file->id));
    Storage::disk('local')->assertMissing(StoredFilePreviewGenerator::legacyWebpPath($file->id));
});

test('file detail separates lightweight webp from explicit whole-document preview', function () {
    $source = file_get_contents(resource_path('js/pages/projects/files/Show.svelte'));

    expect($source)
        ->toContain('previewImageUrl')
        ->toContain("previewKind === 'generated'")
        ->toContain('StoredFilePreviewDialog')
        ->toContain('bind:open={previewOpen}')
        ->toContain("{t('Preview')}");
});

test('full office preview reads an existing cached pdf without running LibreOffice', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.docx', 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => 'report.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, $file->path),
        'cached pdf bytes',
    );
    Process::fake();

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertContent('cached pdf bytes');

    Process::assertNotRan(fn (PendingProcess $process) => $process->command[0] === config('services.libreoffice.path'));
});

test('full office preview never trusts an old id-only pdf cache for the current source', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    $sourcePath = 'projects/test/report.docx';
    Storage::disk('local')->put($sourcePath, 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $sourcePath,
        'original_name' => 'report.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    Storage::disk('local')->put(StoredFilePreviewGenerator::legacyPdfPath($file->id), 'old cached pdf bytes');

    Process::fake(function (PendingProcess $process): object {
        $command = $process->command;

        if (($command[0] ?? null) === config('services.libreoffice.path')) {
            $outputDirectory = $command[array_search('--outdir', $command, true) + 1];
            $inputPath = $command[array_key_last($command)];
            file_put_contents($outputDirectory.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', 'current cached pdf bytes');
        }

        return Process::result();
    });

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertContent('current cached pdf bytes');

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === config('services.libreoffice.path'));
});

test('full office preview does not publish a cache after the source changes during conversion', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    $oldPath = 'projects/test/old-report.docx';
    $newPath = 'projects/test/new-report.docx';
    Storage::disk('local')->put($oldPath, 'old office bytes');
    Storage::disk('local')->put($newPath, 'new office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $oldPath,
        'original_name' => 'report.docx',
    ]);

    Process::fake(function (PendingProcess $process) use ($file, $newPath): object {
        $command = $process->command;

        if (($command[0] ?? null) === config('services.libreoffice.path')) {
            StoredFile::query()->whereKey($file->id)->update([
                'path' => $newPath,
                'preview_status' => 'pending',
            ]);
            $outputDirectory = $command[array_search('--outdir', $command, true) + 1];
            $inputPath = $command[array_key_last($command)];
            file_put_contents($outputDirectory.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf', 'stale pdf bytes');
        }

        return Process::result();
    });

    expect(app(StoredFilePreviewGenerator::class)->officePreviewPdf($file))->toBeNull();
    Storage::disk('local')->assertMissing(
        StoredFilePreviewGenerator::cachedPdfPath($file->id, $oldPath),
    );
});
