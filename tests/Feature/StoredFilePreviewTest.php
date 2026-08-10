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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

function configureOnlyOfficeThumbnails(): void
{
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://onlyoffice-documentserver',
        'onlyoffice.public_url' => 'https://office.example.com',
        'onlyoffice.internal_url' => 'http://app.internal.test',
        'onlyoffice.jwt_secret' => 'test-onlyoffice-secret-0123456789012345',
        'onlyoffice.instance_id' => 'jin-thumbnail-test',
        'onlyoffice.download_ttl' => 300,
        'onlyoffice.healthcheck_timeout' => 3,
        'onlyoffice.max_file_size' => 26214400,
        'onlyoffice.document_server_max_download_bytes' => 26214400,
    ]);
}

test('stored file creation schedules previews for pdf and enabled office extensions', function () {
    Queue::fake();
    configureOnlyOfficeThumbnails();

    ['project' => $project] = storedFilePreviewProject();

    $pdf = StoredFile::factory()->for($project)->create([
        'original_name' => 'source.pdf',
    ]);

    expect($pdf->preview_status)->toBe('pending')
        ->and($pdf->preview_path)->toBeNull();

    foreach (['doc', 'docx', 'xls', 'xlsx', 'xlsm', 'ods', 'odt', 'ppt', 'pptx', 'odp'] as $extension) {
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

    Queue::assertPushed(GenerateStoredFilePreview::class, fn (GenerateStoredFilePreview $job): bool => $job->queue === null);
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
            && $job->queue === null
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

test('office uploads enter the generated preview queue when OnlyOffice is enabled', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOfficeThumbnails();
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
            && $job->sourcePath === $storedFile->path,
    );
});

test('office uploads stay out of the generated preview queue when OnlyOffice is disabled', function () {
    Storage::fake('local');
    Queue::fake();
    config(['onlyoffice.enabled' => false]);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = storedFilePreviewProject();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('report.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])
        ->assertRedirect();

    $storedFile = StoredFile::query()->sole();

    expect($storedFile->preview_status)->toBeNull();
    Queue::assertNotPushed(GenerateStoredFilePreview::class);
});

test('a post-commit queue failure keeps the committed row and original blob', function () {
    Storage::fake('local');
    config(['stored-file-content.enabled' => false]);
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

test('the preview generator converts an office first page to a source-versioned webp', function () {
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    configureOnlyOfficeThumbnails();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.docx', 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => 'report.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    Http::fake([
        'http://onlyoffice-documentserver/converter*' => Http::response([
            'endConvert' => true,
            'fileType' => 'png',
            'fileUrl' => 'http://onlyoffice-documentserver/cache/thumbnail.png',
        ]),
        'http://onlyoffice-documentserver/cache/thumbnail.png' => Http::response("\x89PNG\r\n\x1a\npng bytes", 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);
    Process::fake(function (PendingProcess $process) {
        if (($process->command[0] ?? null) === config('services.imagemagick.path')) {
            file_put_contents($process->command[array_key_last($process->command)], 'webp bytes');
        }

        return Process::result();
    });

    (new GenerateStoredFilePreview($file->id, $file->path))->handle(app(StoredFilePreviewGenerator::class));

    $file->refresh();

    expect(StoredFilePreviewGenerator::supports($file->original_name))->toBeTrue()
        ->and($file->preview_status)->toBe('ready')
        ->and($file->preview_path)->toBe(StoredFilePreviewGenerator::webpPath($file->id, $file->path));
    Storage::disk('local')->assertExists(StoredFilePreviewGenerator::webpPath($file->id, $file->path));
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'http://onlyoffice-documentserver/converter?shardkey=')
        && $request['filetype'] === 'docx'
        && $request['outputtype'] === 'png'
        && $request['thumbnail'] === [
            'aspect' => 1,
            'first' => true,
            'height' => 1600,
            'width' => 1600,
        ]
        && is_string($request['token'])
        && $request['token'] !== '');
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://onlyoffice-documentserver/cache/thumbnail.png');
    Process::assertNotRan(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === config('services.poppler.path'));
    Process::assertRanTimes(fn (PendingProcess $process): bool => ($process->command[0] ?? null) === config('services.imagemagick.path')
        && in_array('-trim', $process->command, true));
});

test('office thumbnail conversion rejects a cross-origin result URL', function () {
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    configureOnlyOfficeThumbnails();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.xlsx', 'office bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.xlsx',
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
    Http::fake([
        'http://onlyoffice-documentserver/converter*' => Http::response([
            'endConvert' => true,
            'fileType' => 'png',
            'fileUrl' => 'https://attacker.example/thumbnail.png',
        ]),
    ]);
    Process::fake();

    expect(fn () => (new GenerateStoredFilePreview($file->id, $file->path))
        ->handle(app(StoredFilePreviewGenerator::class)))
        ->toThrow(RuntimeException::class, 'OnlyOffice thumbnail conversion returned an invalid result.');

    expect($file->refresh()->preview_status)->toBe('processing')
        ->and($file->preview_path)->toBeNull();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'attacker.example'));
    Process::assertNothingRan();
});

test('office files remain unsupported when OnlyOffice is disabled', function () {
    config(['onlyoffice.enabled' => false]);

    expect(StoredFilePreviewGenerator::supports('report.docx'))->toBeFalse()
        ->and(StoredFilePreviewGenerator::supports('report.xlsx'))->toBeFalse()
        ->and(StoredFilePreviewGenerator::supports('report.pdf'))->toBeTrue();
});

test('the legacy preview endpoint streams office originals without conversion', function (string $extension, string $mimeType) {
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

    Process::fake();

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', $mimeType)
        ->assertStreamedContent('office bytes');
    Process::assertNothingRan();
})->with([
    ['xlsm', 'application/vnd.ms-excel.sheet.macroEnabled.12'],
    ['ods', 'application/vnd.oasis.opendocument.spreadsheet'],
    ['odt', 'application/vnd.oasis.opendocument.text'],
    ['odp', 'application/vnd.oasis.opendocument.presentation'],
]);

test('preview conversion retries before terminal failure and keeps the original blob', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = storedFilePreviewProject();
    Storage::disk('local')->put('projects/test/report.pdf', 'original bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
    ]);
    Process::fake(fn () => Process::result(errorOutput: 'conversion failed', exitCode: 1));

    $job = new GenerateStoredFilePreview($file->id, $file->path);

    expect(fn () => $job->handle(app(StoredFilePreviewGenerator::class)))
        ->toThrow(RuntimeException::class, 'PDF page rendering failed.');
    expect($file->refresh()->preview_status)->toBe('processing');

    $job->failed(new RuntimeException('Retry budget exhausted.'));

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
    $oldPath = 'projects/test/old-report.pdf';
    $newPath = 'projects/test/new-report.pdf';
    Storage::disk('local')->put($oldPath, 'old pdf bytes');
    Storage::disk('local')->put($newPath, 'new pdf bytes');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $oldPath,
        'original_name' => 'report.pdf',
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
    expect(fn () => $job->handle($generator))
        ->toThrow(RuntimeException::class, 'conversion failed');
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

test('ready thumbnail metadata returns not found when the derived blob is missing', function () {
    Storage::fake('local');
    ['owner' => $owner, 'member' => $member, 'team' => $team, 'project' => $project] = storedFilePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'missing-preview.pdf',
    ]);
    $file->update([
        'preview_path' => StoredFilePreviewGenerator::webpPath($file->id, $file->path),
        'preview_status' => 'ready',
    ]);

    $this->actingAs($member)
        ->get(route('projects.files.thumbnail', [$team, $project, $file]))
        ->assertNotFound();
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

test('office preview ignores an obsolete converted pdf cache', function () {
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
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->assertStreamedContent('office bytes');

    Process::assertNothingRan();
});

test('office preview ignores an obsolete id-only pdf cache', function () {
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

    Process::fake();

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->assertStreamedContent('office bytes');

    Process::assertNothingRan();
});

test('the local preview pipeline contains no office converter integration', function () {
    expect(file_get_contents(app_path('Support/StoredFilePreviewGenerator.php')))
        ->not->toContain('soffice')
        ->not->toContain('libreoffice')
        ->not->toContain('officePreviewPdf')
        ->and(file_get_contents(config_path('services.php')))
        ->not->toContain('libreoffice');
});
