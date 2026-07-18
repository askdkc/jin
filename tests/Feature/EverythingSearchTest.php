<?php

use App\Enums\TeamRole;
use App\Models\Comment;
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Support\Collection;
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
        'body' => '固有検索語を含む本文',
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

test('comments link back to their message or todo tool', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $message = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create();
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create();
    Comment::factory()->for($project)->for($owner, 'user')->for($message, 'commentable')->create(['body' => 'メッセージ側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($todo, 'commentable')->create(['body' => 'To-do側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($file, 'commentable')->create(['body' => 'ファイル側コメント']);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'type' => 'comment']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 3)
            ->where('results.data', function (Collection $items): bool {
                $targets = $items->pluck('target')->sort()->values()->all();

                return $targets === ['files', 'messages', 'todos']
                    && $items->every(fn (array $item): bool => $item['project']['id'] > 0
                        && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['updatedAt']) === 1);
            }));
});
