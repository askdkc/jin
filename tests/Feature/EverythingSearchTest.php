<?php

use App\Enums\TeamRole;
use App\Models\CardColumn;
use App\Models\CardTable;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    $todo = Todo::factory()->for($todoList)->for($member, 'creator')->create(['title' => '確認作業']);
    Document::factory()->for($project)->for($member, 'creator')->create(['title' => '運用文書']);
    $file = StoredFile::factory()->for($project)->for($member, 'uploader')->create(['original_name' => '仕様.pdf']);
    ExternalLink::factory()->for($project)->for($member, 'creator')->create(['title' => '参考リンク']);
    Comment::factory()->for($project)->for($member, 'user')->for($message, 'commentable')->create(['body' => '確認コメント']);

    $this->actingAs($member)
        ->get(route('everything.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('everything/Index')
            ->has('results.data', 6)
            ->where('results.data', function (Collection $items) use ($file, $message, $project, $team, $todo, $todoList): bool {
                $types = $items->pluck('type')->sort()->values()->all();
                $byType = $items->keyBy('type');

                return $types === ['comment', 'document', 'file', 'link', 'message', 'todo']
                    && $byType['message']['href'] === route(
                        'projects.messages.show',
                        [$team, $project, $message],
                        absolute: false,
                    )
                    && $byType['todo']['href'] === route(
                        'projects.todos.show',
                        [$team, $project, $todoList, $todo],
                        absolute: false,
                    )
                    && $byType['document']['href'] === route(
                        'projects.documents.index',
                        [$team, $project],
                        absolute: false,
                    )
                    && $byType['file']['href'] === route(
                        'projects.files.show',
                        [$team, $project, $file],
                        absolute: false,
                    )
                    && $byType['link']['href'] === route(
                        'projects.materials.index',
                        [$team, $project],
                        absolute: false,
                    )
                    && $byType['comment']['href'] === route(
                        'projects.messages.show',
                        [$team, $project, $message],
                        absolute: false,
                    )
                    && $items->every(fn (array $item): bool => ! array_key_exists('target', $item));
            })
            ->where('projects.0.id', $project->id)
            ->where('projects.0.name', '横断対象'));
});

test('everything results fall back to the project when destination tools are disabled', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'enabled_tools' => [],
    ]);
    $message = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create([
        'title' => '無効ツールのメッセージ',
    ]);
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => '無効ツールのTo-do',
    ]);
    Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '無効ツールの文書',
    ]);
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => '無効ツールのファイル.txt',
        'preview_path' => "projects/{$project->id}/previews/disabled-tool.webp",
        'preview_status' => 'ready',
    ]);
    ExternalLink::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '無効ツールのリンク',
    ]);
    $event = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create();
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $cardColumn = CardColumn::factory()->for($cardTable)->create();

    foreach ([$message, $todo, $todoList, $file, $event, $prompt, $cardColumn] as $commentable) {
        Comment::factory()
            ->for($project)
            ->for($owner, 'user')
            ->for($commentable, 'commentable')
            ->create(['body' => '無効ツールのコメント']);
    }

    $fallback = route('projects.show', [$team, $project], absolute: false);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => '無効ツール']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 12)
            ->where(
                'results.data',
                fn (Collection $items): bool => $items->every(
                    fn (array $item): bool => $item['href'] === $fallback,
                )
                    && $items->firstWhere('type', 'file')['thumbnailUrl'] === null
                    && $items->firstWhere('type', 'file')['canAccessFile'] === false,
            ));
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

test('everything search links subtasks to their parent todo detail', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $todoList = TodoList::factory()->for($project)->create();
    $parent = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create();
    $subtask = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $parent->id,
        'title' => '検索対象の子任務',
    ]);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => '検索対象の子任務', 'type' => 'todo']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.id', $subtask->id)
            ->where('results.data.0.href', route(
                'projects.todos.show',
                [$team, $project, $todoList, $parent],
                absolute: false,
            )));
});

test('comments attached to subtasks link to the todo index', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $todoList = TodoList::factory()->for($project)->create();
    $parent = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create();
    $subtask = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $parent->id,
    ]);
    Comment::factory()
        ->for($project)
        ->for($owner, 'user')
        ->for($subtask, 'commentable')
        ->create(['body' => '子任務だけのコメント']);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'q' => '子任務だけのコメント', 'type' => 'comment']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where(
                'results.data.0.href',
                route('projects.todos.index', [$team, $project], absolute: false),
            ));
});

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
            ->where('results.data.0.href', route(
                'projects.files.show',
                [$team, $project, $file],
                absolute: false,
            ))
            ->where('results.data.0.mimeType', 'application/pdf')
            ->where('results.data.0.thumbnailUrl', route(
                'projects.files.thumbnail',
                [$team, $project, $file],
                absolute: false,
            ))
            ->where('results.data.0.canAccessFile', true)
            ->where('results.data.0.size', 4096)
            ->where('results.data.0.uploader.id', $owner->id)
            ->where('results.data.0.uploader.name', $owner->name)
            ->where('results.data.0.uploader.avatar', $owner->avatar)
            ->where('results.data.0.createdAt', $file->created_at?->toISOString())
            ->missing('results.data.0.snippet')
            ->missing('results.data.0.target')
            ->missing('results.data.0.route_parent_id')
            ->missing('results.data.0.route_resource_id')
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

test('everything search filters files by their selected category', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    foreach ([
        ['original_name' => 'diagram.PNG', 'mime_type' => ' IMAGE/PNG '],
        ['original_name' => 'recording.mp4', 'mime_type' => 'VIDEO/MP4; codecs=h264'],
        ['original_name' => 'handbook.PDF', 'mime_type' => 'APPLICATION/PDF ; charset=binary'],
        ['original_name' => 'proposal.docx', 'mime_type' => 'application/zip'],
        ['original_name' => 'budget.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet ; charset=binary'],
        ['original_name' => 'slides.ppt', 'mime_type' => 'application/vnd.ms-office'],
        ['original_name' => 'archive.zip', 'mime_type' => 'application/zip'],
        ['original_name' => 'fake.docx', 'mime_type' => 'application/octet-stream'],
        ['original_name' => 'wrong.pdf', 'mime_type' => 'text/plain'],
        ['original_name' => 'tabbed.pdf', 'mime_type' => "application/pdf\t; charset=binary"],
        ['original_name' => 'unknown.png', 'mime_type' => null],
        ['original_name' => 'notes.txt', 'mime_type' => 'text/plain'],
    ] as $attributes) {
        StoredFile::factory()->for($project)->for($owner, 'uploader')->create($attributes);
    }

    MessageBoardPost::factory()->for($project)->for($owner, 'user')->create([
        'title' => 'Non-file result',
    ]);

    foreach ([
        'image' => ['diagram.PNG'],
        'video' => ['recording.mp4'],
        'pdf' => ['handbook.PDF'],
        'office' => ['budget.xlsx', 'proposal.docx', 'slides.ppt'],
    ] as $category => $expectedTitles) {
        $this->actingAs($owner)
            ->get(route('everything.index', [
                $team,
                'file_categories' => [$category],
                'sort' => 'name_asc',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.data', fn (Collection $items): bool => $items
                    ->pluck('title')
                    ->all() === $expectedTitles
                    && $items->every(fn (array $item): bool => $item['type'] === 'file'))
                ->where('filters.type', 'file')
                ->where('filters.fileCategories', [$category]));
    }
});

test('file category selections use OR semantics and compose with other filters', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $otherProject->members()->attach($member);

    foreach ([
        ['original_name' => 'campaign-image.png', 'mime_type' => 'image/png'],
        ['original_name' => 'campaign-report.pdf', 'mime_type' => 'application/pdf'],
        ['original_name' => 'campaign-video.mp4', 'mime_type' => 'video/mp4'],
        ['original_name' => 'unrelated-image.png', 'mime_type' => 'image/png'],
    ] as $attributes) {
        StoredFile::factory()->for($project)->for($member, 'uploader')->create($attributes);
    }

    StoredFile::factory()->for($otherProject)->for($member, 'uploader')->create([
        'original_name' => 'campaign-other-project.pdf',
        'mime_type' => 'application/pdf',
    ]);
    StoredFile::factory()->for($hiddenProject)->for($owner, 'uploader')->create([
        'original_name' => 'campaign-secret.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($member)
        ->get(route('everything.index', [
            $team,
            'q' => 'campaign',
            'type' => 'message',
            'project' => $project->id,
            'sort' => 'name_desc',
            'file_categories' => ['image', 'pdf'],
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.data', fn (Collection $items): bool => $items
                ->pluck('title')
                ->all() === ['campaign-report.pdf', 'campaign-image.png'])
            ->where('results.total', 2)
            ->where('filters.q', 'campaign')
            ->where('filters.type', 'file')
            ->where('filters.project', $project->id)
            ->where('filters.sort', 'name_desc')
            ->where('filters.fileCategories', ['image', 'pdf']));

    $this->actingAs($member)
        ->get(route('everything.index', [
            $team,
            'q' => 'campaign',
            'sort' => 'name_desc',
            'file_categories' => ['image', 'pdf'],
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.data', fn (Collection $items): bool => $items
                ->pluck('title')
                ->all() === [
                    'campaign-report.pdf',
                    'campaign-other-project.pdf',
                    'campaign-image.png',
                ])
            ->where('results.total', 3));
});

test('everything rejects malformed file category filters', function (mixed $fileCategories, string $errorKey) {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'file_categories' => $fileCategories]))
        ->assertSessionHasErrors($errorKey);
})->with([
    'unsupported category' => [['archive'], 'file_categories.0'],
    'scalar value' => ['image', 'file_categories'],
    'duplicate category' => [['image', 'image'], 'file_categories.1'],
    'non-list array' => [[1 => 'image'], 'file_categories'],
    'more than four categories' => [['image', 'video', 'office', 'pdf', 'image'], 'file_categories'],
]);

test('file category pagination retains normalized filters and correct totals', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    StoredFile::factory()
        ->count(25)
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'original_name' => fake()->unique()->lexify('image-????.png'),
            'mime_type' => 'image/png',
        ]);
    StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'excluded.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->actingAs($owner)
        ->get(route('everything.index', [
            $team,
            'type' => 'comment',
            'file_categories' => ['image'],
            'sort' => 'name_asc',
            'page' => 2,
            'ignored' => 'value',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 5)
            ->where('results.total', 25)
            ->where('results.current_page', 2)
            ->where('filters.type', 'file')
            ->where('filters.fileCategories', ['image'])
            ->where('results.links', function (Collection $links): bool {
                $expectedQuery = [
                    'file_categories' => ['image'],
                    'page' => '1',
                    'sort' => 'name_asc',
                    'type' => 'file',
                ];
                ksort($expectedQuery);

                return $links->contains(function (array $link) use ($expectedQuery): bool {
                    if (! is_string($link['url'])) {
                        return false;
                    }

                    parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);
                    ksort($query);

                    return $query === $expectedQuery;
                });
            }));
});

test('everything results support update and name sorting', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'Alpha',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);
    MessageBoardPost::factory()->for($project)->for($owner, 'user')->create([
        'title' => 'Zulu',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    ExternalLink::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'Mike',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    foreach ([
        [null, ['Mike', 'Zulu', 'Alpha']],
        ['updated_desc', ['Mike', 'Zulu', 'Alpha']],
        ['updated_asc', ['Alpha', 'Zulu', 'Mike']],
        ['name_asc', ['Alpha', 'Mike', 'Zulu']],
        ['name_desc', ['Zulu', 'Mike', 'Alpha']],
    ] as [$sort, $expectedTitles]) {
        $parameters = $sort === null ? [$team] : [$team, 'sort' => $sort];

        $this->actingAs($owner)
            ->get(route('everything.index', $parameters))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.data', fn (Collection $items): bool => $items
                    ->pluck('title')
                    ->all() === $expectedTitles)
                ->where('filters.sort', $sort ?? 'updated_desc'));
    }
});

test('everything sorting uses deterministic cross-type tie breakers', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $updatedAt = now()->subDay();
    $sharedId = 10_000;
    $message = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create([
        'id' => $sharedId,
        'title' => 'Same title',
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'id' => $sharedId,
        'title' => 'Same title',
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);

    expect($message->id)->toBe($document->id);

    foreach (['updated_desc', 'updated_asc', 'name_asc', 'name_desc'] as $sort) {
        $this->actingAs($owner)
            ->get(route('everything.index', [$team, 'sort' => $sort]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.data', fn (Collection $items): bool => $items
                    ->pluck('type')
                    ->all() === ['document', 'message']));
    }
});

test('everything update sorting places missing timestamps last', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'Dated item',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    $undated = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'Undated item',
    ]);
    DB::table('documents')->where('id', $undated->id)->update(['updated_at' => null]);

    foreach (['updated_desc', 'updated_asc'] as $sort) {
        $this->actingAs($owner)
            ->get(route('everything.index', [$team, 'sort' => $sort]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.data', fn (Collection $items): bool => $items
                    ->pluck('title')
                    ->all() === ['Dated item', 'Undated item']));
    }
});

test('everything rejects unsupported sort values', function (string $sort) {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'sort' => $sort]))
        ->assertSessionHasErrors('sort');
})->with([
    'database column' => 'updated_at',
    'direction only' => 'desc',
    'SQL-like value' => 'title desc',
    'unknown option' => 'relevance',
]);

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
        ->get(route('everything.index', [
            $team,
            'q' => 'ページング対象',
            'type' => 'document',
            'project' => $project->id,
            'sort' => 'name_desc',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 20)
            ->where('results.total', 25)
            ->where('results.current_page', 1)
            ->where('results.links', fn (Collection $links): bool => $links->contains(
                fn (array $link): bool => is_string($link['url'])
                    && str_contains($link['url'], 'q=%E3%83%9A%E3%83%BC%E3%82%B8%E3%83%B3%E3%82%B0%E5%AF%BE%E8%B1%A1')
                    && str_contains($link['url'], 'type=document')
                    && str_contains($link['url'], "project={$project->id}")
                    && str_contains($link['url'], 'sort=name_desc'),
            )));
});

test('comments link back to their exact parent records', function () {
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
    $cardTable = CardTable::factory()->for($project)->create();
    $cardColumn = CardColumn::factory()->for($cardTable)->create();
    Comment::factory()->for($project)->for($owner, 'user')->for($message, 'commentable')->create(['body' => 'メッセージ側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($todo, 'commentable')->create(['body' => 'To-do側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($todoList, 'commentable')->create(['body' => 'To-doリスト側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($file, 'commentable')->create(['body' => 'ファイル側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($event, 'commentable')->create(['body' => '予定側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($prompt, 'commentable')->create(['body' => 'チェックイン側コメント']);
    Comment::factory()->for($project)->for($owner, 'user')->for($cardColumn, 'commentable')->create(['body' => 'カード列側コメント']);

    $expectedHrefs = [
        'メッセージ側コメント' => route('projects.messages.show', [$team, $project, $message], absolute: false),
        'To-do側コメント' => route('projects.todos.show', [$team, $project, $todoList, $todo], absolute: false),
        'To-doリスト側コメント' => route('projects.todo-lists.show', [$team, $project, $todoList], absolute: false),
        'ファイル側コメント' => route('projects.files.show', [$team, $project, $file], absolute: false),
        '予定側コメント' => route('projects.schedule.show', [$team, $project, $event], absolute: false),
        'チェックイン側コメント' => route(
            'projects.check-ins.show',
            [$team, $project, $checkIn],
            absolute: false,
        ),
        'カード列側コメント' => route(
            'projects.card-columns.show',
            [$team, $project, $cardTable, $cardColumn],
            absolute: false,
        ),
    ];

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'type' => 'comment']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 7)
            ->where('results.data', function (Collection $items) use ($expectedHrefs): bool {
                $hrefs = $items->mapWithKeys(fn (array $item): array => [
                    $item['snippet'] => $item['href'],
                ])->all();
                ksort($hrefs);
                ksort($expectedHrefs);

                return $hrefs === $expectedHrefs
                    && $items->every(fn (array $item): bool => ! array_key_exists('target', $item)
                        && $item['project']['id'] > 0
                        && preg_match('/^\d{4}-\d{2}-\d{2}/', $item['updatedAt']) === 1);
            }));
});

test('comments with missing parents fall back to their project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $comment = Comment::factory()->for($project)->for($owner, 'user')->make([
        'body' => '親なしコメント',
    ]);
    $comment->commentable_type = MessageBoardPost::class;
    $comment->commentable_id = 999_999;
    $comment->save();

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'type' => 'comment']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where(
                'results.data.0.href',
                route('projects.show', [$team, $project], absolute: false),
            ));
});

test('comment parent records are hydrated in batches', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $message = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create();
    Comment::factory()
        ->count(5)
        ->for($project)
        ->for($owner, 'user')
        ->for($message, 'commentable')
        ->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($owner)
        ->get(route('everything.index', [$team, 'type' => 'comment']))
        ->assertOk();

    $messageParentQueries = collect($queries)->filter(
        fn (string $sql): bool => str_contains($sql, 'from "message_board_posts"'),
    );

    expect($messageParentQueries)->toHaveCount(1);
});
