<?php

use App\Enums\TeamRole;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Comment;
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('everything view combines the searchable project content types', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '横断対象']);
    $project->members()->attach($member);
    $message = MessageBoardPost::factory()->for($project)->for($member, 'user')->create(['title' => '告知']);
    $todoList = TodoList::factory()->for($project)->create();
    Todo::factory()->for($todoList)->for($member, 'creator')->create(['title' => '確認作業']);
    Document::factory()->for($project)->for($member, 'creator')->create(['title' => '運用文書']);
    StoredFile::factory()->for($project)->for($member, 'uploader')->create(['original_name' => '仕様.pdf']);
    ExternalLink::factory()->for($project)->for($member, 'creator')->create(['title' => '参考リンク']);
    Comment::factory()->for($project)->for($member, 'user')->for($message, 'commentable')->create(['body' => '確認コメント']);

    $this->actingAs($member)
        ->get(route('everything.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('everything/Index')
            ->has('results.data', 6)
            ->where('results.data', function (Collection $items): bool {
                $types = $items->pluck('type')->sort()->values()->all();

                return $types === ['comment', 'document', 'file', 'link', 'message', 'todo'];
            })
            ->where('projects.0.id', $project->id)
            ->where('projects.0.name', '横断対象'));
});

test('everything search finds files by extension or full filename', function (string $query) {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $pdf = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => '仕様.pdf',
    ]);
    StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => '構成図.png',
        'mime_type' => 'image/png',
    ]);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => $query]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.id', $pdf->id)
            ->where('results.data.0.type', 'file')
            ->where('results.data.0.title', '仕様.pdf')
            ->where('filters.q', $query));
})->with([
    'extension' => '.pdf',
    'full filename' => '仕様.pdf',
]);

test('everything search exposes rich file metadata without private storage fields', function () {
    Storage::fake('public');

    $owner = User::factory()->create([
        'avatar_path' => 'avatars/search-owner.jpg',
    ]);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => "projects/{$project->id}/private/design-preview.pdf",
            'original_name' => 'design-preview.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4096,
            'preview_path' => "projects/{$project->id}/previews/design-preview.webp",
            'preview_status' => 'ready',
        ]));

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => 'design-preview']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.id', $file->id)
            ->where('results.data.0.type', 'file')
            ->where('results.data.0.title', 'design-preview.pdf')
            ->where('results.data.0.mimeType', 'application/pdf')
            ->where('results.data.0.thumbnailUrl', route(
                'projects.files.thumbnail',
                [$team, $project, $file],
                absolute: false,
            ))
            ->where('results.data.0.size', 4096)
            ->where('results.data.0.uploader.id', $owner->id)
            ->where('results.data.0.uploader.name', $owner->name)
            ->where('results.data.0.uploader.avatar', $owner->avatar)
            ->where('results.data.0.createdAt', $file->created_at?->toISOString())
            ->missing('results.data.0.snippet')
            ->missing('results.data.0.disk')
            ->missing('results.data.0.path')
            ->missing('results.data.0.preview_path')
            ->missing('results.data.0.uploader.avatar_path'));
});

test('everything search filters by words type and project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $otherProject->members()->attach($member);
    Document::factory()->for($project)->for($member, 'creator')->create([
        'title' => '通常文書',
        'body' => '<p><strong>固有検索語</strong>を含む &lt;本文&gt;</p>',
    ]);
    MessageBoardPost::factory()->for($project)->for($member, 'user')->create([
        'title' => '固有検索語のお知らせ',
    ]);
    Document::factory()->for($otherProject)->for($member, 'creator')->create([
        'title' => '固有検索語を含む別案件',
    ]);

    $this->actingAs($member)
        ->get(route('everything.index', [
            $team,
            'q' => '固有検索語',
            'type' => 'document',
            'project' => $project->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.type', 'document')
            ->where('results.data.0.title', '通常文書')
            ->where('results.data.0.snippet', '固有検索語を含む <本文>')
            ->where('filters.q', '固有検索語')
            ->where('filters.type', 'document')
            ->where('filters.project', $project->id));
});

test('everything view does not expose inaccessible archived or other team projects', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '閲覧可能']);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '権限外']);
    $archivedProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '終了済み',
        'archived_at' => now(),
    ]);
    $otherTeamProject = Project::factory()->for($otherTeam)->for($owner, 'creator')->create(['name' => '別チーム']);
    $visibleProject->members()->attach($member);
    $archivedProject->members()->attach($member);

    foreach ([$visibleProject, $hiddenProject, $archivedProject, $otherTeamProject] as $project) {
        Document::factory()->for($project)->for($owner, 'creator')->create([
            'title' => "{$project->name}の機密文書",
            'body' => '同じ検索語',
        ]);
    }

    $this->actingAs($member)
        ->get(route('everything.index', [$team, 'q' => '同じ検索語']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.project.id', $visibleProject->id)
            ->has('projects', 1)
            ->where('projects.0.id', $visibleProject->id));

    $this->actingAs($member)
        ->get(route('everything.index', [$team, 'project' => $hiddenProject->id]))
        ->assertSessionHasErrors('project');
});

test('team managers can search all active projects in their team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Document::factory()->for($project)->for($owner, 'creator')->create(['title' => '管理対象']);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => '管理対象']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.project.id', $project->id)
            ->has('projects', 1));
});

test('everything results are paginated and retain search filters', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Document::factory()
        ->count(25)
        ->for($project)
        ->for($owner, 'creator')
        ->create(['body' => 'ページング対象']);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => 'ページング対象', 'type' => 'document']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 20)
            ->where('results.total', 25)
            ->where('results.current_page', 1)
            ->where('results.links', fn (Collection $links): bool => $links->contains(
                fn (array $link): bool => is_string($link['url'])
                    && str_contains($link['url'], 'q=%E3%83%9A%E3%83%BC%E3%82%B8%E3%83%B3%E3%82%B0%E5%AF%BE%E8%B1%A1')
                    && str_contains($link['url'], 'type=document'),
            )));
});

test('comments link back to their parent tool', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $message = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create();
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create();
    $event = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create();
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    Comment::factory()->for($project)->for($owner, 'user')->for($message, 'commentable')->create(['body' => 'メッセージ側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($todo, 'commentable')->create(['body' => 'To-do側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($file, 'commentable')->create(['body' => 'ファイル側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($event, 'commentable')->create(['body' => '予定側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($prompt, 'commentable')->create(['body' => 'チェックイン側コメント']);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'type' => 'comment']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 5)
            ->where('results.data', function (Collection $items): bool {
                $targets = $items->pluck('target')->sort()->values()->all();

                return $targets === ['check_ins', 'files', 'messages', 'schedule', 'todos']
                    && $items->every(fn (array $item): bool => $item['project']['id'] > 0
                        && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['updatedAt']) === 1);
            }));
});
