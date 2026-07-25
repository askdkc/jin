<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Jobs\GenerateStoredFilePreview;
use App\Models\Activity;
use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Support\StoredFileDeletion;
use App\Support\StoredFilePreviewGenerator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('materials library is served from the files URL with ordered folders and file metadata', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $laterFolder = MaterialFolder::factory()->for($project)->for($owner, 'creator')->create([
        'name' => '納品物',
        'position' => 2,
    ]);
    $firstFolder = MaterialFolder::factory()->for($project)->for($owner, 'creator')->create([
        'name' => '確認用',
        'position' => 1,
    ]);
    StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'material_folder_id' => $laterFolder->id,
        'original_name' => 'デザイン案.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
    ]);
    StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'material_folder_id' => $laterFolder->id,
        'path' => "projects/{$project->id}/attachments/pasted-image.png",
        'original_name' => '貼り付け画像.png',
        'mime_type' => 'image/png',
        'is_inline' => true,
    ]);

    $libraryUrl = route('projects.materials.index', [
        $team,
        $project,
        'folder' => $laterFolder->id,
    ]);

    expect(route('projects.materials.index', [$team, $project], absolute: false))
        ->toBe("/{$team->slug}/projects/{$project->id}/files");

    $this->actingAs($owner)
        ->get($libraryUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/materials/Index')
            ->has('items', 1)
            ->where('items.0.type', 'file')
            ->where('items.0.title', 'デザイン案.pdf')
            ->where('items.0.updaterName', $owner->name)
            ->where('items.0.folderId', $laterFolder->id)
            ->where('items.0.folderName', '納品物')
            ->where('items.0.canDelete', true)
            ->has('folders', 2)
            ->where('folders.0.id', $firstFolder->id)
            ->where('folders.1.id', $laterFolder->id)
            ->where('folders.1.itemCount', 1));
});

test('materials library exposes thumbnail metadata without private storage fields', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $readyPdf = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'original_name' => 'ready.pdf',
            'mime_type' => 'application/pdf',
            'preview_path' => 'previews/ready.webp',
            'preview_status' => 'ready',
        ]));
    $pendingPdf = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'original_name' => 'pending.pdf',
            'mime_type' => 'application/pdf',
            'preview_path' => null,
            'preview_status' => 'pending',
        ]));
    $unsupportedFile = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'original_name' => 'report.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'preview_path' => null,
            'preview_status' => null,
        ]));

    $this->actingAs($owner)
        ->get(route('projects.materials.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items', function (Collection $items) use ($team, $project, $readyPdf, $pendingPdf, $unsupportedFile): bool {
                $ready = $items->firstWhere('id', $readyPdf->id);
                $pending = $items->firstWhere('id', $pendingPdf->id);
                $unsupported = $items->firstWhere('id', $unsupportedFile->id);

                return $ready['mimeType'] === 'application/pdf'
                    && $ready['thumbnailUrl'] === route(
                        'projects.files.thumbnail',
                        [$team, $project, $readyPdf],
                        absolute: false,
                    )
                    && $pending['mimeType'] === 'application/pdf'
                    && $pending['thumbnailUrl'] === null
                    && $unsupported['mimeType'] === $unsupportedFile->mime_type
                    && $unsupported['thumbnailUrl'] === null
                    && ! array_key_exists('disk', $ready)
                    && ! array_key_exists('path', $ready)
                    && ! array_key_exists('preview_path', $ready);
            }));
});

test('project participants can upload and download private files', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $filesUrl = route('projects.materials.index', [$team, $project]);

    $this->actingAs($member)
        ->from($filesUrl)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('運用資料.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect($filesUrl);

    $storedFile = StoredFile::query()->firstOrFail();
    Storage::disk('local')->assertExists($storedFile->path);

    $download = $this->actingAs($member)
        ->get(route('projects.files.download', [$team, $project, $storedFile]))
        ->assertOk();

    expect($download->headers->get('content-disposition'))
        ->toContain("filename*=utf-8''".rawurlencode('運用資料.pdf'))
        ->and($storedFile->uploaded_by)->toBe($member->id)
        ->and($storedFile->path)->not->toContain('運用資料.pdf')
        ->and($storedFile->checksum)->toBe(StoredFile::checksumFor(Storage::disk('local'), $storedFile->path))
        ->and($storedFile->size)->toBe(Storage::disk('local')->size($storedFile->path));
});

test('additional office formats validate without entering the local preview queue', function () {
    Storage::fake('local');
    Queue::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $files = [
        ['name' => 'macros.xlsm', 'mime' => 'application/vnd.ms-excel.sheet.macroEnabled.12'],
        ['name' => 'budget.ods', 'mime' => 'application/vnd.oasis.opendocument.spreadsheet'],
        ['name' => 'memo.odt', 'mime' => 'application/vnd.oasis.opendocument.text'],
        ['name' => 'slides.odp', 'mime' => 'application/vnd.oasis.opendocument.presentation'],
    ];

    foreach ($files as ['name' => $name, 'mime' => $mime]) {
        $this->actingAs($owner)
            ->post(route('projects.files.store', [$team, $project]), [
                'file' => UploadedFile::fake()->create($name, 10, $mime),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    expect(StoredFile::query()->count())->toBe(4)
        ->and(StoredFile::query()->whereNotNull('preview_status')->count())->toBe(0);
    Queue::assertNotPushed(GenerateStoredFilePreview::class);
});

test('folder uploads create or reuse a material folder for every uploaded file', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $storeUrl = route('projects.files.store', [$team, $project]);

    $this->actingAs($owner)
        ->post($storeUrl, [
            'files' => [
                UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg'),
            ],
            'folder_name' => 'Client Assets',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $folder = MaterialFolder::query()->sole();

    expect($folder->name)->toBe('Client Assets')
        ->and($folder->created_by)->toBe($owner->id)
        ->and(StoredFile::query()->pluck('material_folder_id')->all())
        ->toBe([$folder->id, $folder->id]);

    $this->actingAs($owner)
        ->post($storeUrl, [
            'files' => [
                UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ],
            'folder_name' => 'Client Assets',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(MaterialFolder::query()->count())->toBe(1)
        ->and(StoredFile::query()->whereBelongsTo($folder, 'materialFolder')->count())->toBe(3);
});

test('project participants can preview private office files inline', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    Storage::disk('local')->put('projects/test/preview.pdf', 'pdf preview content');
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/preview.pdf',
        'original_name' => '確認資料.pdf',
        'mime_type' => 'application/pdf',
        'size' => 19,
    ]);

    $response = $this->actingAs($member)
        ->get(route('projects.files.preview', [$team, $project, $storedFile]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent('pdf preview content');

    expect($response->headers->get('content-disposition'))
        ->toStartWith('inline;')
        ->not->toContain('attachment');
});

test('office originals are streamed without consulting old conversion caches', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Storage::disk('local')->put('projects/test/report.docx', 'raw docx content');
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => '月次報告.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => 15,
    ]);
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::cachedPdfPath($storedFile->id, $storedFile->path),
        'converted pdf content',
    );

    $response = $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $storedFile]))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->assertStreamedContent('raw docx content');

    expect($response->headers->get('content-disposition'))->toStartWith('inline;');
});

test('office preview endpoint does not depend on a local converter', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Storage::disk('local')->put('projects/test/report.docx', 'raw docx content');
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => '月次報告.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => 15,
    ]);

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $storedFile]))
        ->assertOk()
        ->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        )
        ->assertStreamedContent('raw docx content');
});

test('deleting a stored file removes its blobs and comments without breaking activity history', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Storage::disk('local')->put('projects/test/report.docx', 'raw docx content');
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.docx',
        'original_name' => '月次報告.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => 15,
    ]);
    $comment = $storedFile->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'ファイルと一緒に削除',
    ]);
    $activity = Activity::record(
        $project,
        $owner,
        ActivityType::FileUploaded,
        $storedFile,
        $storedFile->original_name,
    );
    $otherProject = Project::factory()->for(Team::factory())->for($owner, 'creator')->create();
    $unrelatedActivity = Activity::factory()->for($otherProject)->for($owner, 'actor')->create([
        'subject_type' => $storedFile->getMorphClass(),
        'subject_id' => $storedFile->id,
        'metadata' => ['label' => '別案件の履歴'],
    ]);
    Storage::disk('local')->put(
        StoredFilePreviewGenerator::cachedPdfPath($storedFile->id, $storedFile->path),
        'converted pdf content',
    );

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $storedFile]))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('projects/test/report.docx');
    Storage::disk('local')->assertMissing(
        StoredFilePreviewGenerator::cachedPdfPath($storedFile->id, $storedFile->path),
    );
    expect(StoredFile::query()->count())->toBe(0)
        ->and($comment->fresh())->toBeNull()
        ->and($activity->fresh())->not->toBeNull()
        ->and($activity->fresh()?->metadata['subjectDeleted'])->toBeTrue()
        ->and($unrelatedActivity->fresh()?->metadata)->toBe(['label' => '別案件の履歴']);

    $this->get(route('activity.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.id', $activity->id)
            ->where('activities.0.target', 'files')
            ->where('activities.0.subjectId', null));
});

test('a deletion interrupted after removing the blob is completed after its lease expires', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/interrupted.pdf',
        'original_name' => 'interrupted.pdf',
    ]);
    Storage::disk('local')->put($file->path, 'pdf');
    $failFinalization = true;
    Event::listen(
        'eloquent.deleting: '.StoredFile::class,
        function (StoredFile $deleting) use ($file, &$failFinalization): void {
            if ($failFinalization && $deleting->is($file)) {
                $failFinalization = false;

                throw new RuntimeException('Forced finalization failure.');
            }
        },
    );

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $file])))
        ->toThrow(RuntimeException::class, 'Forced finalization failure.');

    Storage::disk('local')->assertMissing($file->path);
    expect($file->fresh()?->preview_status)->toBe('deleting');
    $file->forceFill(['updated_at' => now()->subSeconds(61)])->saveQuietly();

    $this->withExceptionHandling();
    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $file]))
        ->assertRedirect();

    expect($file->fresh())->toBeNull();
});

test('a deleting file is retried only after its lease expires while the blob still exists', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $inProgress = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/in-progress.pdf',
        'original_name' => 'in-progress.pdf',
    ]);
    $stale = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/stale.pdf',
        'original_name' => 'stale.pdf',
    ]);
    Storage::disk('local')->put($inProgress->path, 'pdf');
    Storage::disk('local')->put($stale->path, 'pdf');
    $inProgress->forceFill([
        'preview_status' => 'deleting',
        'updated_at' => now(),
    ])->saveQuietly();
    $stale->forceFill([
        'preview_status' => 'deleting',
        'updated_at' => now()->subSeconds(61),
    ])->saveQuietly();

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $inProgress]))
        ->assertRedirect();
    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $stale]))
        ->assertRedirect();

    Storage::disk('local')->assertExists($inProgress->path);
    Storage::disk('local')->assertMissing($stale->path);
    expect($inProgress->fresh()?->preview_status)->toBe('deleting')
        ->and($stale->fresh())->toBeNull();
});

test('a storage deletion failure restores the file only while the same lease is held', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/failing.pdf',
        'original_name' => 'failing.pdf',
    ]);
    $file->forceFill([
        'preview_path' => 'previews/failing.webp',
        'preview_status' => 'ready',
    ])->saveQuietly();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->twice()->with($file->path)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($file->path)->andReturnFalse();
    Storage::shouldReceive('disk')->twice()->with('local')->andReturn($disk);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $file])))
        ->toThrow(RuntimeException::class, 'File deletion failed.');

    expect($file->fresh()?->preview_path)->toBe('previews/failing.webp')
        ->and($file->fresh()?->preview_status)->toBe('ready');
});

test('deleting an inline file removes its rich text reference', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'attachable_type' => Todo::class,
        'attachable_id' => $todo->id,
        'path' => 'projects/test/screenshot.png',
        'original_name' => 'screenshot.png',
        'mime_type' => 'image/png',
    ]);
    $previewUrl = route('projects.files.preview', [$team, $project, $storedFile], absolute: false);
    $todo->update([
        'details' => "<div><figure><a href=\"{$previewUrl}\"><img src=\"{$previewUrl}\" alt=\"screenshot.png\"></a></figure><p>Keep this note.</p></div>",
    ]);
    Storage::disk('local')->put($storedFile->path, 'image');

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $storedFile]))
        ->assertRedirect();

    expect($todo->refresh()->details)
        ->not->toContain("/files/{$storedFile->id}/")
        ->toContain('Keep this note.');
});

test('csv previews are served inline as utf 8 without mojibake', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $utf8Csv = "氏名,部署\n山田太郎,営業部\n";
    Storage::disk('local')->put(
        'projects/test/members.csv',
        mb_convert_encoding($utf8Csv, 'SJIS-win', 'UTF-8'),
    );
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/members.csv',
        'original_name' => '社員一覧.csv',
        'mime_type' => 'text/csv',
        'size' => Storage::disk('local')->size('projects/test/members.csv'),
    ]);

    $response = $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $storedFile]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition', 'inline')
        ->assertContent($utf8Csv);

    expect($response->headers->get('content-disposition'))->not->toContain('attachment');
});

test('file uploads validate mime type extension and size', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('file');

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('payload.pdf', 10, 'application/x-msdownload'),
        ])
        ->assertSessionHasErrors('file');

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('large.pdf', 200 * 1024 + 1, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'files' => [
                UploadedFile::fake()->create('valid.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
            ],
        ])
        ->assertSessionHasErrors('files.1');

    expect(StoredFile::query()->exists())->toBeFalse();
});

test('project file uploads reject files whose combined size exceeds the request limit', function () {
    Storage::fake('local');
    config()->set('uploads.max_request_megabytes', 15);

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'files' => [
                UploadedFile::fake()->create('first.pdf', 8 * 1024, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 8 * 1024, 'application/pdf'),
            ],
        ])
        ->assertSessionHasErrors('files');

    expect(StoredFile::query()->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('project file upload request limit applies to a single file and reads runtime configuration', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $storeUrl = route('projects.files.store', [$team, $project]);

    config()->set('uploads.max_request_megabytes', 5);

    $this->actingAs($owner)
        ->post($storeUrl, [
            'file' => UploadedFile::fake()->create('report.pdf', 6 * 1024, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    config()->set('uploads.max_request_megabytes', 6);

    $this->actingAs($owner)
        ->post($storeUrl, [
            'file' => UploadedFile::fake()->create('report.pdf', 6 * 1024, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(StoredFile::query()->count())->toBe(1);
});

test('project file uploads delete every stored blob when database persistence fails', function () {
    Storage::fake('local');
    Queue::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    Event::listen('eloquent.creating: '.Activity::class, function (): void {
        throw new RuntimeException('Activity persistence failed.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'files' => [
                UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
            ],
        ]))->toThrow(RuntimeException::class, 'Activity persistence failed.');

    expect(StoredFile::query()->exists())->toBeFalse()
        ->and(Activity::query()->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('project file uploads accept the configured 200 megabyte boundary', function () {
    Storage::fake('local');
    Queue::fake();
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('boundary.pdf', 200 * 1024, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(StoredFile::query()->count())->toBe(1);
});

test('archiving a project retains its private files', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Storage::disk('local')->put('projects/test/archive.pdf', 'archive');
    $storedFile = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/archive.pdf',
    ]);

    $this->actingAs($owner)
        ->patch(route('projects.archive', [$team, $project]))
        ->assertRedirect(route('projects.index', $team));

    Storage::disk('local')->assertExists($storedFile->path);
    expect($storedFile->fresh())->not->toBeNull();
});

test('uploaders and team managers can delete files while other members cannot', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $uploader = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($uploader, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$uploader->id, $otherMember->id]);
    Storage::disk('local')->put('projects/test/report.pdf', 'report');
    $storedFile = StoredFile::factory()->for($project)->for($uploader, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
    ]);

    $this->actingAs($otherMember)
        ->delete(route('projects.files.destroy', [$team, $project, $storedFile]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $storedFile]))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('projects/test/report.pdf');
    expect($storedFile->fresh())->toBeNull();
});

test('uploaders and team managers can rename files while other members cannot', function () {
    $owner = User::factory()->create();
    $uploader = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($uploader, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$uploader->id, $otherMember->id]);
    $storedFile = StoredFile::factory()->for($project)->for($uploader, 'uploader')->create([
        'original_name' => 'old-name.pdf',
    ]);

    $this->actingAs($otherMember)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => 'forbidden.pdf',
        ])
        ->assertForbidden();

    $this->actingAs($uploader)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => '',
        ])
        ->assertSessionHasErrors('original_name');

    $this->actingAs($uploader)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => 'renamed.txt',
        ])
        ->assertSessionHasErrors('original_name');

    $this->actingAs($uploader)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => 'renamed',
        ])
        ->assertSessionHasErrors('original_name');

    $this->actingAs($uploader)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => 'renamed.PDF',
        ])
        ->assertRedirect();

    expect($storedFile->fresh()->original_name)->toBe('renamed.PDF');

    $this->actingAs($owner)
        ->patch(route('projects.files.update', [$team, $project, $storedFile]), [
            'original_name' => 'managed.pdf',
        ])
        ->assertRedirect();

    expect($storedFile->fresh()->original_name)->toBe('managed.pdf');
});

test('file routes reject non participants and cross project bindings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $storedFile = StoredFile::factory()->for($otherProject)->for($owner, 'uploader')->create();

    $this->actingAs($member)
        ->get(route('projects.materials.index', [$team, $otherProject]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('projects.files.download', [$team, $visibleProject, $storedFile]))
        ->assertNotFound();

    $this->actingAs($member)
        ->get(route('projects.files.preview', [$team, $visibleProject, $storedFile]))
        ->assertNotFound();

    $this->actingAs($member)
        ->patch(route('projects.files.update', [$team, $visibleProject, $storedFile]), [
            'original_name' => 'renamed.pdf',
        ])
        ->assertNotFound();

    $this->actingAs($member)
        ->delete(route('projects.files.destroy', [$team, $visibleProject, $storedFile]))
        ->assertNotFound();
});

test('a project member can open a file detail page with its comments while outsiders cannot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => '仕様書.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4096,
    ]);
    $file->comments()->create([
        'project_id' => $project->id,
        'user_id' => $member->id,
        'body' => '確認しました。',
    ]);
    $file->reactors()->attach($member);

    $this->actingAs($member)
        ->get(route('projects.files.show', [$team, $project, $file]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/files/Show')
            ->where('file.name', '仕様書.pdf')
            ->where('file.size', 4096)
            ->where('file.uploaderName', $owner->name)
            ->where('reactions.0.reaction', '🚀')
            ->where('reactions.0.count', 1)
            ->where('reactions.0.reacted', true)
            ->where('reactions.0.names.0', $member->name)
            ->where('comments.0.body', '確認しました。'));

    $this->actingAs($outsider)
        ->get(route('projects.files.show', [$team, $project, $file]))
        ->assertForbidden();
});

test('project members can toggle a reaction on a file', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create();
    $reactionUrl = route('projects.files.reaction.toggle', [$team, $project, $file]);

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '👍'])->assertRedirect();
    expect($file->reactors()->whereKey($member->id)->first()?->pivot?->reaction)->toBe('👍');

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '👍'])->assertRedirect();
    expect($file->reactors()->whereKey($member->id)->exists())->toBeFalse();

    $this->actingAs($outsider)->post($reactionUrl, ['reaction' => '👍'])->assertForbidden();
});

test('project members can comment on a file', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create();

    $this->actingAs($member)
        ->post(route('projects.files.comments.store', [$team, $project, $file]), [
            'body' => '<p>ありがとうございます</p>',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($file->comments()->count())->toBe(1);
});

test('the uploader can replace a file with a new version keeping its name', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.files.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('資料.pdf', 10, 'application/pdf'),
        ])
        ->assertRedirect();

    $file = StoredFile::query()->firstOrFail();
    $oldPath = $file->path;

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create('新版.pdf', 25, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $file->refresh();

    expect($file->path)->not->toBe($oldPath)
        ->and($file->original_name)->toBe('資料.pdf');
    Storage::disk('local')->assertExists($file->path);
    Storage::disk('local')->assertMissing($oldPath);
});

test('a replacement must keep the current filename extension', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($file->path, 'old pdf');

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create('archive.zip', 10, 'application/zip'),
        ])
        ->assertSessionHasErrors('file');

    expect($file->fresh()->path)->toBe('projects/test/report.pdf');
    Storage::disk('local')->assertExists('projects/test/report.pdf');
});

test('same-extension replacement supports macro-enabled workbooks', function () {
    Storage::fake('local');
    Queue::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsm",
        'original_name' => 'report.xlsm',
        'mime_type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
    ]);
    Storage::disk('local')->put($file->path, 'old workbook');
    $oldPath = $file->path;

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create(
                'new-report.xlsm',
                25,
                'application/vnd.ms-excel.sheet.macroEnabled.12',
            ),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $file->refresh();

    expect($file->path)->not->toBe($oldPath)
        ->and($file->original_name)->toBe('report.xlsm')
        ->and($file->preview_status)->toBeNull()
        ->and($file->checksum)->toBe(StoredFile::checksumFor(Storage::disk('local'), $file->path))
        ->and($file->size)->toBe(Storage::disk('local')->size($file->path));
    Queue::assertNotPushed(GenerateStoredFilePreview::class);
});

test('a failed replacement update removes the newly stored blob', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/report.pdf',
        'original_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($file->path, 'old pdf');
    $shouldFail = true;
    Event::listen(
        'eloquent.updating: '.StoredFile::class,
        function (StoredFile $updating) use ($file, &$shouldFail): void {
            if ($shouldFail && $updating->is($file)) {
                $shouldFail = false;

                throw new RuntimeException('Forced replacement failure.');
            }
        },
    );

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create('new-report.pdf', 10, 'application/pdf'),
        ]))->toThrow(RuntimeException::class, 'Forced replacement failure.');

    expect($file->fresh()->path)->toBe('projects/test/report.pdf')
        ->and(Storage::disk('local')->allFiles())->toBe(['projects/test/report.pdf']);
});

test('replacement against an already-deleting row returns a conflict without changing the row or orphaning the upload', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $oldPath = 'projects/test/report.pdf';
    Storage::disk('local')->put($oldPath, 'old pdf');
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $oldPath,
        'original_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
    ]);
    $file->update(['preview_status' => 'deleting']);

    $this->actingAs($owner)
        ->post(route('projects.files.replace', [$team, $project, $file]), [
            'file' => UploadedFile::fake()->create('new-report.pdf', 10, 'application/pdf'),
        ])
        ->assertConflict();

    expect($file->refresh()->path)->toBe($oldPath)
        ->and($file->preview_status)->toBe('deleting')
        ->and(Storage::disk('local')->allFiles())->toBe([$oldPath]);
});

test('final deletion CAS leaves a changed stored file and its relations untouched', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => 'projects/test/old-report.pdf',
        'original_name' => 'report.pdf',
    ]);
    $comment = $file->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'Keep this comment.',
    ]);
    $expectedPath = $file->path;
    $file->update([
        'path' => 'projects/test/new-report.pdf',
        'preview_status' => 'pending',
    ]);

    $finalizeDeletion = new ReflectionMethod(StoredFileDeletion::class, 'finalize');
    expect($finalizeDeletion->invoke(app(StoredFileDeletion::class), $file->id, [
        'path' => $expectedPath,
        'lease_token' => (string) $file->getRawOriginal('updated_at'),
    ]))->toBeFalse();

    expect($file->fresh()->path)->toBe('projects/test/new-report.pdf')
        ->and($comment->fresh())->not->toBeNull();
});
