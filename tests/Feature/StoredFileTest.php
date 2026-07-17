<?php

use App\Enums\TeamRole;
use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('file library exposes ordered folders and clear file metadata', function () {
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

    $this->actingAs($owner)
        ->get(route('projects.files.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/files/Index')
            ->has('files', 1)
            ->where('files.0.name', 'デザイン案.pdf')
            ->where('files.0.mimeType', 'application/pdf')
            ->where('files.0.size', 2048)
            ->where('files.0.uploaderName', $owner->name)
            ->where('files.0.folderId', $laterFolder->id)
            ->where('files.0.folderName', '納品物')
            ->where('files.0.canDelete', true)
            ->has('folders', 2)
            ->where('folders.0.id', $firstFolder->id)
            ->where('folders.1.id', $laterFolder->id));
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

    $filesUrl = route('projects.files.index', [$team, $project]);

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
        ->and($storedFile->path)->not->toContain('運用資料.pdf');
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

test('office previews are served from the cached pdf conversion', function () {
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
    Storage::disk('local')->put("previews/{$storedFile->id}.pdf", 'converted pdf content');

    $this->actingAs($owner)
        ->get(route('projects.files.preview', [$team, $project, $storedFile]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline')
        ->assertContent('converted pdf content');
});

test('office previews fall back to the original file when conversion is unavailable', function () {
    Storage::fake('local');
    config(['services.libreoffice.path' => '/nonexistent/soffice']);

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

test('deleting a stored file removes its cached preview pdf', function () {
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
    Storage::disk('local')->put("previews/{$storedFile->id}.pdf", 'converted pdf content');

    $this->actingAs($owner)
        ->delete(route('projects.files.destroy', [$team, $project, $storedFile]))
        ->assertRedirect();

    Storage::disk('local')->assertMissing('projects/test/report.docx');
    Storage::disk('local')->assertMissing("previews/{$storedFile->id}.pdf");
    expect(StoredFile::query()->count())->toBe(0);
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
            'file' => UploadedFile::fake()->create('large.pdf', 10 * 1024 + 1, 'application/pdf'),
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
            'original_name' => 'renamed.pdf',
        ])
        ->assertRedirect();

    expect($storedFile->fresh()->original_name)->toBe('renamed.pdf');

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
        ->get(route('projects.files.index', [$team, $otherProject]))
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
