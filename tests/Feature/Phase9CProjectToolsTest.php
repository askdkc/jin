<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('workspace calendar combines only accessible active project schedules', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '参加中',
        'starts_on' => '2026-07-15',
        'ends_on' => '2026-09-15',
    ]);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '非参加',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-09-30',
    ]);
    $archivedProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '終了済み',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-09-30',
        'archived_at' => now(),
    ]);
    $otherTeamProject = Project::factory()->for($otherTeam)->for($owner, 'creator')->create([
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-09-30',
    ]);
    $visibleProject->members()->attach($member);
    $archivedProject->members()->attach($member);

    ScheduleEvent::factory()->for($visibleProject)->for($owner, 'creator')->create([
        'title' => '月をまたぐ予定',
        'starts_at' => '2026-07-31 23:00:00',
        'ends_at' => '2026-08-01 01:00:00',
    ]);
    ScheduleEvent::factory()->for($visibleProject)->for($owner, 'creator')->create([
        'title' => '月外の予定',
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);
    ScheduleEvent::factory()->for($visibleProject)->for($owner, 'creator')->create([
        'title' => '週内の予定',
        'starts_at' => '2026-08-05 10:00:00',
        'ends_at' => '2026-08-05 11:00:00',
    ]);
    ScheduleEvent::factory()->for($hiddenProject)->for($owner, 'creator')->create([
        'title' => '非参加の予定',
        'starts_at' => '2026-08-03 10:00:00',
        'ends_at' => '2026-08-03 11:00:00',
    ]);
    ScheduleEvent::factory()->for($archivedProject)->for($owner, 'creator')->create([
        'title' => '終了済みの予定',
        'starts_at' => '2026-08-04 10:00:00',
        'ends_at' => '2026-08-04 11:00:00',
    ]);
    ScheduleEvent::factory()->for($otherTeamProject)->for($owner, 'creator')->create([
        'title' => '別チームの予定',
        'starts_at' => '2026-08-05 10:00:00',
        'ends_at' => '2026-08-05 11:00:00',
    ]);

    $this->actingAs($member)
        ->get(route('calendar.index', [$team, 'month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/Index')
            ->where('month', '2026-08')
            ->where('previousMonth', '2026-07')
            ->where('nextMonth', '2026-09')
            ->where('view', 'month')
            ->where('date', '2026-08-01')
            ->where('rangeStart', '2026-08-01')
            ->where('rangeEnd', '2026-08-31')
            ->has('events', 2)
            ->where('events.0.title', '月をまたぐ予定')
            ->where('events.0.project.id', $visibleProject->id));

    $this->get(route('calendar.index', [$team, 'view' => 'day', 'date' => '2026-08-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'day')
            ->where('rangeStart', '2026-08-01')
            ->where('rangeEnd', '2026-08-01')
            ->where('previousDate', '2026-07-31')
            ->where('nextDate', '2026-08-02')
            ->has('events', 1)
            ->where('events.0.title', '月をまたぐ予定'));

    $this->get(route('calendar.index', [$team, 'view' => 'week', 'date' => '2026-08-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'week')
            ->where('rangeStart', '2026-08-02')
            ->where('rangeEnd', '2026-08-08')
            ->where('previousDate', '2026-07-29')
            ->where('nextDate', '2026-08-12')
            ->has('events', 1)
            ->where('events.0.title', '週内の予定'));

    $this->get(route('calendar.index', [$team, 'view' => 'gantt', 'date' => '2026-08-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'gantt')
            ->where('rangeStart', '2026-06-28')
            ->where('rangeEnd', '2026-09-19')
            ->where('previousDate', '2026-06-24')
            ->where('nextDate', '2026-09-16')
            ->has('events', 3)
            ->has('ganttProjects', 1)
            ->where('ganttProjects.0.name', '参加中')
            ->where('ganttProjects.0.startsOn', '2026-07-15')
            ->where('ganttProjects.0.endsOn', '2026-09-15'));

    $this->get(route('calendar.index', [$team, 'view' => 'year', 'date' => 'invalid']))
        ->assertSessionHasErrors(['view', 'date']);
});

test('team managers can see every active project in the workspace calendar', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '管理対象の予定',
        'starts_at' => '2026-08-05 10:00:00',
        'ends_at' => '2026-08-05 11:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('calendar.index', [$team, 'month' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.title', '管理対象の予定'));
});

test('project participants can create folders and external links in the material library', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    $this->actingAs($member)
        ->post(route('projects.material-folders.store', [$team, $project]), ['name' => '企画資料'])
        ->assertRedirect(route('projects.materials.index', [$team, $project]));

    $folder = MaterialFolder::query()->firstOrFail();

    $this->actingAs($member)
        ->post(route('projects.external-links.store', [$team, $project]), [
            'title' => '参考サイト',
            'url' => 'https://example.com/reference',
            'material_folder_id' => $folder->id,
        ])
        ->assertRedirect(route('projects.materials.index', [$team, $project]));

    $link = ExternalLink::query()->firstOrFail();

    expect($folder->created_by)->toBe($member->id)
        ->and($folder->position)->toBe(1)
        ->and($link->material_folder_id)->toBe($folder->id)
        ->and($link->created_by)->toBe($member->id)
        ->and(Activity::query()->where('type', ActivityType::ExternalLinkCreated)->exists())->toBeTrue();
});

test('material library combines documents files and links and applies its filters', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $folder = MaterialFolder::factory()->for($project)->for($member, 'creator')->create(['name' => '共有']);
    Document::factory()->for($project)->for($member, 'creator')->create([
        'title' => '共有ドキュメント',
        'material_folder_id' => $folder->id,
    ]);
    StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => '設計.pdf',
        'material_folder_id' => $folder->id,
    ]);
    ExternalLink::factory()->for($project)->for($member, 'creator')->create([
        'title' => '共有リンク',
        'material_folder_id' => null,
    ]);

    $this->actingAs($member)
        ->get(route('projects.materials.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/materials/Index')
            ->has('items', 1)
            ->where('items.0.type', 'link')
            ->where('items.0.title', '共有リンク')
            ->where('folders.0.itemCount', 2)
            ->has('members', 2));

    $this->actingAs($member)
        ->get(route('projects.materials.index', [
            $team,
            $project,
            'type' => 'document',
            'folder' => $folder->id,
            'updater' => $member->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.type', 'document')
            ->where('items.0.title', '共有ドキュメント')
            ->where('filters.folder', $folder->id)
            ->where('filters.updater', $member->id));
});

test('files dropped in materials return to the current root or folder', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $folder = MaterialFolder::factory()->for($project)->for($member, 'creator')->create();

    $this->actingAs($member)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => 'フォルダー内文書',
            'body' => '本文',
            'material_folder_id' => $folder->id,
        ])
        ->assertRedirect();

    $folderUrl = route('projects.materials.index', [
        $team,
        $project,
        'folder' => $folder->id,
    ]);

    $this->actingAs($member)
        ->from($folderUrl)
        ->post(route('projects.files.store', [$team, $project]), [
            'files' => [
                UploadedFile::fake()->create('folder-a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('folder-b.pdf', 10, 'application/pdf'),
            ],
            'material_folder_id' => $folder->id,
        ])
        ->assertRedirect($folderUrl);

    $rootUrl = route('projects.materials.index', [$team, $project]);

    $this->actingAs($member)
        ->from($rootUrl)
        ->post(route('projects.files.store', [$team, $project]), [
            'files' => [
                UploadedFile::fake()->create('root-a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('root-b.pdf', 10, 'application/pdf'),
            ],
        ])
        ->assertRedirect($rootUrl);

    expect(Document::query()->firstOrFail()->material_folder_id)->toBe($folder->id)
        ->and(StoredFile::query()->whereIn('original_name', ['folder-a.pdf', 'folder-b.pdf'])->where('material_folder_id', $folder->id)->count())->toBe(2)
        ->and(StoredFile::query()->whereIn('original_name', ['root-a.pdf', 'root-b.pdf'])->whereNull('material_folder_id')->count())->toBe(2);
});

test('material routes reject cross project folders and scoped bindings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $otherFolder = MaterialFolder::factory()->for($otherProject)->for($owner, 'creator')->create();
    $otherLink = ExternalLink::factory()->for($otherProject)->for($owner, 'creator')->create();
    $document = Document::factory()->for($project)->for($member, 'creator')->create();

    $this->actingAs($member)
        ->post(route('projects.external-links.store', [$team, $project]), [
            'title' => '不正なリンク',
            'url' => 'https://example.com',
            'material_folder_id' => $otherFolder->id,
        ])
        ->assertSessionHasErrors('material_folder_id');

    $this->actingAs($member)
        ->patch(route('projects.materials.documents.move', [$team, $project, $document]), [
            'material_folder_id' => $otherFolder->id,
        ])
        ->assertSessionHasErrors('material_folder_id');

    $this->actingAs($member)
        ->delete(route('projects.material-folders.destroy', [$team, $project, $otherFolder]))
        ->assertNotFound();

    $this->actingAs($member)
        ->delete(route('projects.external-links.destroy', [$team, $project, $otherLink]))
        ->assertNotFound();
});

test('folder deletion clears material references and respects author permissions', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $folder = MaterialFolder::factory()->for($project)->for($author, 'creator')->create();
    $document = Document::factory()->for($project)->for($author, 'creator')->create([
        'material_folder_id' => $folder->id,
    ]);
    $file = StoredFile::factory()->for($project)->for($author, 'uploader')->create([
        'material_folder_id' => $folder->id,
    ]);
    $link = ExternalLink::factory()->for($project)->for($author, 'creator')->create([
        'material_folder_id' => $folder->id,
    ]);

    $this->actingAs($otherMember)
        ->delete(route('projects.material-folders.destroy', [$team, $project, $folder]))
        ->assertForbidden();

    $this->actingAs($author)
        ->delete(route('projects.material-folders.destroy', [$team, $project, $folder]))
        ->assertRedirect();

    expect($document->fresh()->material_folder_id)->toBeNull()
        ->and($file->fresh()->material_folder_id)->toBeNull()
        ->and($link->fresh()->material_folder_id)->toBeNull();
});

test('only link authors and team managers can delete an external link', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $link = ExternalLink::factory()->for($project)->for($author, 'creator')->create();

    $this->actingAs($otherMember)
        ->delete(route('projects.external-links.destroy', [$team, $project, $link]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('projects.external-links.destroy', [$team, $project, $link]))
        ->assertRedirect();

    expect($link->fresh())->toBeNull();
});
