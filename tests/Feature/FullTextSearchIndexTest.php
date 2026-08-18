<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Support\FullTextSearch;
use Illuminate\Support\Facades\DB;

function fullTextSearchIndexContains(string $table, int $rowId, string $term): bool
{
    return DB::table($table)
        ->where('rowid', $rowId)
        ->whereRaw("{$table} MATCH ?", ['"'.str_replace('"', '""', $term).'"'])
        ->exists();
}

test('sqlite creates trigram indexes and synchronization triggers for every target', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This test requires SQLite FTS5.');
    }

    $targets = [
        'message_board_posts_fts',
        'todos_fts',
        'chat_messages_fts',
        'documents_fts',
        'stored_files_fts',
        'external_links_fts',
        'comments_fts',
        'projects_fts',
        'users_fts',
        'activities_fts',
    ];

    foreach ($targets as $target) {
        $table = DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $target],
        );

        expect($table)->not->toBeNull()
            ->and(strtolower((string) $table->sql))->toContain('trigram');

        foreach (['insert', 'update', 'delete'] as $operation) {
            expect(DB::selectOne(
                'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
                ['trigger', "{$target}_{$operation}"],
            ))->not->toBeNull();
        }
    }
});

test('sqlite full text indexes backfill and synchronize inserts updates deletes and json labels', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This test requires SQLite FTS5.');
    }

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '記号 %_ "quoted" workspace',
    ]);
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '初期検索語',
    ]);

    expect(fullTextSearchIndexContains('documents_fts', $document->id, '初期検索語'))->toBeTrue();
    expect(fullTextSearchIndexContains('projects_fts', $project->id, '%_ "quoted"'))->toBeTrue();

    $document->update(['title' => '更新検索語']);

    expect(fullTextSearchIndexContains('documents_fts', $document->id, '初期検索語'))->toBeFalse()
        ->and(fullTextSearchIndexContains('documents_fts', $document->id, '更新検索語'))->toBeTrue();

    $document->delete();

    expect(fullTextSearchIndexContains('documents_fts', $document->id, '更新検索語'))->toBeFalse();

    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => 'NULL詳細検索語',
        'details' => null,
    ]);

    expect(fullTextSearchIndexContains('todos_fts', $todo->id, 'NULL詳細検索語'))->toBeTrue();

    $activity = Activity::factory()->for($project)->for($owner, 'actor')->create([
        'type' => ActivityType::TodoCreated,
        'metadata' => ['label' => 'ラベル検索語'],
    ]);

    expect(fullTextSearchIndexContains('activities_fts', $activity->id, 'ラベル検索語'))->toBeTrue();

    $activity->update(['metadata' => ['label' => '更新された別ラベル']]);

    expect(fullTextSearchIndexContains('activities_fts', $activity->id, 'ラベル検索語'))->toBeFalse()
        ->and(fullTextSearchIndexContains('activities_fts', $activity->id, '更新された別ラベル'))->toBeTrue();
});

test('the shared service uses the sqlite indexes for a real query', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This test requires SQLite FTS5.');
    }

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'body' => '本文側の共通検索語',
    ]);
    $query = Document::query();

    app(FullTextSearch::class)->apply($query, FullTextSearch::DOCUMENTS, '共通検索語');

    expect($query->whereKey($document->id)->exists())->toBeTrue();
});

test('postgresql creates pgroonga indexes and executes a full text query', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('This test requires PostgreSQL with PGroonga.');
    }

    $extension = DB::selectOne(
        "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pgroonga') AS installed",
    );
    $indexNames = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('indexname', 'like', '%_pgroonga_index')
        ->pluck('indexname')
        ->all();

    expect(filter_var($extension->installed ?? false, FILTER_VALIDATE_BOOL))->toBeTrue()
        ->and($indexNames)->toContain(
            'message_board_posts_title_pgroonga_index',
            'message_board_posts_body_pgroonga_index',
            'todos_title_pgroonga_index',
            'todos_details_pgroonga_index',
            'chat_messages_body_pgroonga_index',
            'documents_title_pgroonga_index',
            'documents_body_pgroonga_index',
            'stored_files_original_name_pgroonga_index',
            'external_links_title_pgroonga_index',
            'external_links_url_pgroonga_index',
            'comments_body_pgroonga_index',
            'projects_name_pgroonga_index',
            'projects_description_pgroonga_index',
            'users_name_pgroonga_index',
            'activities_label_pgroonga_index',
        );

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'PGroongaの実検索語',
    ]);
    $query = Document::query();

    app(FullTextSearch::class)->apply($query, FullTextSearch::DOCUMENTS, 'PGroongaの実検索語');

    expect($query->whereKey($document->id)->exists())->toBeTrue();
});
