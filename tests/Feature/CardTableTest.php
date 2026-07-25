<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Card;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can create and view card tables columns and cards', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'カード担当']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    $this->actingAs($member)
        ->post(route('projects.card-tables.store', [$team, $project]), ['name' => 'リリース準備'])
        ->assertRedirect(route('projects.card-tables.index', [$team, $project]));

    $cardTable = CardTable::query()->firstOrFail();
    $triage = $cardTable->cardColumns()->where('is_triage', true)->sole();
    $notNow = $cardTable->cardColumns()->where('is_not_now', true)->sole();
    $done = $cardTable->cardColumns()->where('is_done', true)->sole();

    expect($cardTable->position)->toBe(0);
    expect($triage)
        ->name->toBe('Triage')
        ->is_collapsed->toBeFalse();
    expect($notNow)
        ->name->toBe('Not Now')
        ->is_collapsed->toBeTrue();
    expect($done)
        ->name->toBe('Done')
        ->is_collapsed->toBeTrue();

    $this->post(route('projects.card-columns.store', [$team, $project, $cardTable]), ['name' => '未着手'])
        ->assertRedirect();

    $column = $cardTable->cardColumns()
        ->where('is_triage', false)
        ->where('is_not_now', false)
        ->where('is_done', false)
        ->sole();

    $this->post(route('projects.cards.store', [$team, $project, $cardTable, $column]), [
        'title' => 'モバイル表示を確認する',
        'description' => '390pxで横にはみ出さないこと',
        'assignee_id' => $member->id,
        'due_on' => '2026-08-01',
    ])->assertRedirect();

    $card = Card::query()->firstOrFail();

    expect($column->position)->toBe(0);
    expect($card)
        ->created_by->toBe($member->id)
        ->assignee_id->toBe($member->id)
        ->position->toBe(0)
        ->version->toBe(1);
    expect(Activity::query()->where('type', ActivityType::CardCreated)->exists())->toBeTrue();

    $this->get(route('projects.card-tables.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/card-tables/Index')
            ->where('tables.0.name', 'リリース準備')
            ->where('tables.0.canDelete', false)
            ->where('tables.0.triage.name', 'Triage')
            ->where('tables.0.triage.canDelete', false)
            ->has('tables.0.triage.cards', 0)
            ->where('tables.0.notNow.name', 'Not Now')
            ->where('tables.0.notNow.collapsed', true)
            ->where('tables.0.notNow.canDelete', false)
            ->has('tables.0.notNow.cards', 0)
            ->where('tables.0.done.name', 'Done')
            ->where('tables.0.done.collapsed', true)
            ->where('tables.0.done.canDelete', false)
            ->has('tables.0.done.cards', 0)
            ->where('tables.0.columns.0.name', '未着手')
            ->where('tables.0.columns.0.canDelete', false)
            ->where('tables.0.columns.0.cards.0.title', 'モバイル表示を確認する')
            ->where('tables.0.columns.0.cards.0.assigneeName', 'カード担当')
            ->where('tables.0.columns.0.cards.0.canDelete', true)
            ->where('tables.0.columns.0.cards.0.version', 1));

    $this->get(route('projects.show', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('home.cardTables.count', 1));
});

test('project participants can view a card column and manage its comments', function () {
    $owner = User::factory()->create(['name' => '作成者']);
    $member = User::factory()->create(['name' => 'コメント担当']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $cardTable = CardTable::factory()->for($project)->create(['name' => '作業盤']);
    $column = CardColumn::factory()->for($cardTable)->create(['name' => '進行中']);
    Card::factory()->for($column, 'cardColumn')->for($owner, 'creator')->create([
        'title' => '確認対象カード',
        'position' => 0,
    ]);
    $existingComment = Comment::factory()
        ->for($project)
        ->for($owner)
        ->for($column, 'commentable')
        ->create(['body' => '<p>既存コメント</p>']);

    $this->actingAs($member)
        ->get(route('projects.card-columns.show', [$team, $project, $cardTable, $column]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/card-tables/ColumnShow')
            ->where('table.name', '作業盤')
            ->where('column.name', '進行中')
            ->where('column.cards.0.title', '確認対象カード')
            ->where('column.comments.0.body', '<p>既存コメント</p>')
            ->where('column.comments.0.canDelete', false)
            ->has('members', 2));

    $this->post(route('projects.card-columns.comments.store', [$team, $project, $cardTable, $column]), [
        'body' => '<p>追加コメント</p><script>alert(1)</script>',
    ])->assertRedirect(route('projects.card-columns.show', [$team, $project, $cardTable, $column]));

    $newComment = $column->comments()->latest('id')->firstOrFail();
    expect($newComment)
        ->project_id->toBe($project->id)
        ->user_id->toBe($member->id)
        ->body->toBe('<p>追加コメント</p>');

    $this->patch(route('projects.card-columns.comments.update', [
        $team,
        $project,
        $cardTable,
        $column,
        $newComment,
    ]), ['body' => '<p>更新コメント</p>'])
        ->assertRedirect(route('projects.card-columns.show', [$team, $project, $cardTable, $column]));

    expect($newComment->fresh()->body)->toBe('<p>更新コメント</p>');

    $this->delete(route('projects.card-columns.comments.destroy', [
        $team,
        $project,
        $cardTable,
        $column,
        $newComment,
    ]))->assertRedirect(route('projects.card-columns.show', [$team, $project, $cardTable, $column]));

    expect($newComment->fresh())->toBeNull()
        ->and($existingComment->fresh())->not->toBeNull();
});

test('triage not now and done columns have detail pages', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();

    $this->actingAs($owner);

    foreach ([
        ['is_triage', 'isTriage'],
        ['is_not_now', 'isNotNow'],
        ['is_done', 'isDone'],
    ] as [$databaseFlag, $propFlag]) {
        $column = $cardTable->cardColumns()->where($databaseFlag, true)->sole();

        $this->get(route('projects.card-columns.show', [$team, $project, $cardTable, $column]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/card-tables/ColumnShow')
                ->where("column.{$propFlag}", true));
    }
});

test('card assignees must participate in the project', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $cardTable = CardTable::factory()->for($project)->create();
    $column = CardColumn::factory()->for($cardTable)->create();

    $this->actingAs($owner)
        ->post(route('projects.cards.store', [$team, $project, $cardTable, $column]), [
            'title' => '不正な担当者',
            'assignee_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('assignee_id');

    expect(Card::query()->exists())->toBeFalse();
});

test('cards move between columns and positions are normalized', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $source = CardColumn::factory()->for($cardTable)->create(['position' => 0]);
    $target = CardColumn::factory()->for($cardTable)->create(['position' => 1]);
    $first = Card::factory()->for($source, 'cardColumn')->for($owner, 'creator')->create(['position' => 0]);
    $moving = Card::factory()->for($source, 'cardColumn')->for($owner, 'creator')->create(['position' => 1]);
    $existing = Card::factory()->for($target, 'cardColumn')->for($owner, 'creator')->create(['position' => 0]);

    $this->actingAs($owner)
        ->patch(route('projects.cards.move', [$team, $project, $cardTable, $source, $moving]), [
            'target_column_id' => $target->id,
            'target_position' => 0,
            'version' => 1,
        ])
        ->assertRedirect();

    expect($first->fresh())
        ->card_column_id->toBe($source->id)
        ->position->toBe(0);
    expect($moving->fresh())
        ->card_column_id->toBe($target->id)
        ->position->toBe(0)
        ->version->toBe(2);
    expect($existing->fresh()->position)->toBe(1);

    $this->patch(route('projects.cards.move', [$team, $project, $cardTable, $target, $moving]), [
        'target_column_id' => $target->id,
        'target_position' => 1,
        'version' => 2,
    ])->assertRedirect();

    expect($existing->fresh()->position)->toBe(0);
    expect($moving->fresh())
        ->position->toBe(1)
        ->version->toBe(3);
});

test('stale card versions are rejected without overwriting newer data', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $column = CardColumn::factory()->for($cardTable)->create();
    $card = Card::factory()->for($column, 'cardColumn')->for($owner, 'creator')->create([
        'title' => '新しい内容',
        'version' => 3,
    ]);

    $this->actingAs($owner)
        ->patch(route('projects.cards.update', [$team, $project, $cardTable, $column, $card]), [
            'title' => '古い画面からの上書き',
            'version' => 2,
        ])
        ->assertSessionHasErrors('version');

    expect($card->fresh())
        ->title->toBe('新しい内容')
        ->version->toBe(3);

    $this->patch(route('projects.cards.update', [$team, $project, $cardTable, $column, $card]), [
        'title' => '確認済みの更新',
        'version' => 3,
    ])->assertRedirect();

    expect($card->fresh())
        ->title->toBe('確認済みの更新')
        ->version->toBe(4);
});

test('card table routes stay scoped to their project table and column', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstTable = CardTable::factory()->for($firstProject)->create();
    $secondTable = CardTable::factory()->for($secondProject)->create();
    $firstColumn = CardColumn::factory()->for($firstTable)->create();
    $secondColumn = CardColumn::factory()->for($secondTable)->create();
    $secondCard = Card::factory()->for($secondColumn, 'cardColumn')->for($owner, 'creator')->create();
    $secondComment = Comment::factory()
        ->for($secondProject)
        ->for($owner)
        ->for($secondColumn, 'commentable')
        ->create();

    $this->actingAs($owner)
        ->post(route('projects.card-columns.store', [$team, $firstProject, $secondTable]), ['name' => '不正'])
        ->assertNotFound();

    $this->patch(route('projects.cards.update', [$team, $firstProject, $firstTable, $firstColumn, $secondCard]), [
        'title' => '不正',
        'version' => 1,
    ])->assertNotFound();

    $this->patch(route('projects.cards.move', [$team, $firstProject, $firstTable, $firstColumn, $secondCard]), [
        'target_column_id' => $secondColumn->id,
        'version' => 1,
    ])->assertNotFound();

    $this->get(route('projects.card-columns.show', [$team, $firstProject, $firstTable, $secondColumn]))
        ->assertNotFound();

    $this->patch(route('projects.card-columns.comments.update', [
        $team,
        $firstProject,
        $firstTable,
        $firstColumn,
        $secondComment,
    ]), ['body' => '不正'])
        ->assertNotFound();
});

test('only project managers can delete card tables and columns', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $cardTable = CardTable::factory()->for($project)->create();
    $column = CardColumn::factory()->for($cardTable)->create();

    $this->actingAs($member)
        ->delete(route('projects.card-columns.destroy', [$team, $project, $cardTable, $column]))
        ->assertForbidden();
    $this->delete(route('projects.card-tables.destroy', [$team, $project, $cardTable]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('projects.card-tables.index', [$team, $project]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tables.0.canDelete', true)
            ->where('tables.0.columns.0.canDelete', true));

    $this->delete(route('projects.card-columns.destroy', [$team, $project, $cardTable, $column]))
        ->assertRedirect();
    $this->delete(route('projects.card-tables.destroy', [$team, $project, $cardTable]))
        ->assertRedirect();

    expect($cardTable->fresh())->toBeNull();
});

test('card deletions close gaps without resequencing system columns', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $firstTable = CardTable::factory()->for($project)->create(['position' => 2]);
    $middleTable = CardTable::factory()->for($project)->create(['position' => 8]);
    $lastTable = CardTable::factory()->for($project)->create(['position' => 13]);
    $firstColumn = CardColumn::factory()->for($firstTable)->create(['position' => 2]);
    $middleColumn = CardColumn::factory()->for($firstTable)->create(['position' => 8]);
    $lastColumn = CardColumn::factory()->for($firstTable)->create(['position' => 13]);
    $systemColumnPositions = $firstTable->cardColumns()
        ->where(fn ($query) => $query
            ->where('is_triage', true)
            ->orWhere('is_not_now', true)
            ->orWhere('is_done', true))
        ->pluck('position', 'id')
        ->all();
    $firstCard = Card::factory()->for($firstColumn, 'cardColumn')->for($owner, 'creator')->create([
        'position' => 2,
    ]);
    $middleCard = Card::factory()->for($firstColumn, 'cardColumn')->for($owner, 'creator')->create([
        'position' => 8,
    ]);
    $lastCard = Card::factory()->for($firstColumn, 'cardColumn')->for($owner, 'creator')->create([
        'position' => 13,
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.cards.destroy', [
            $team,
            $project,
            $firstTable,
            $firstColumn,
            $middleCard,
        ]))
        ->assertRedirect();

    expect($firstColumn->cards()->orderBy('position')->pluck('id')->all())
        ->toBe([$firstCard->id, $lastCard->id])
        ->and($firstColumn->cards()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);

    $this->delete(route('projects.card-columns.destroy', [
        $team,
        $project,
        $firstTable,
        $middleColumn,
    ]))->assertRedirect();

    expect($firstTable->cardColumns()
        ->where('is_triage', false)
        ->where('is_not_now', false)
        ->where('is_done', false)
        ->orderBy('position')
        ->pluck('id')
        ->all())->toBe([$firstColumn->id, $lastColumn->id])
        ->and($firstTable->cardColumns()
            ->where('is_triage', false)
            ->where('is_not_now', false)
            ->where('is_done', false)
            ->orderBy('position')
            ->pluck('position')
            ->all())->toBe([0, 1])
        ->and($firstTable->cardColumns()
            ->whereKey(array_keys($systemColumnPositions))
            ->pluck('position', 'id')
            ->all())->toBe($systemColumnPositions);

    $this->delete(route('projects.card-tables.destroy', [$team, $project, $middleTable]))
        ->assertRedirect();

    expect($project->cardTables()->orderBy('position')->pluck('id')->all())
        ->toBe([$firstTable->id, $lastTable->id])
        ->and($project->cardTables()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);
});

test('system columns can be renamed without changing their system behavior', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $triage = $cardTable->cardColumns()->where('is_triage', true)->sole();
    $notNow = $cardTable->cardColumns()->where('is_not_now', true)->sole();
    $done = $cardTable->cardColumns()->where('is_done', true)->sole();
    $initialPositions = [
        $triage->id => $triage->position,
        $notNow->id => $notNow->position,
        $done->id => $done->position,
    ];

    $this->actingAs($owner);

    foreach ([
        [$triage, '受信箱'],
        [$notNow, '保留'],
        [$done, '完了済み'],
    ] as [$column, $name]) {
        $detailUrl = route('projects.card-columns.show', [$team, $project, $cardTable, $column]);

        $this->from($detailUrl)
            ->patch(route('projects.card-columns.update', [$team, $project, $cardTable, $column]), [
                'name' => $name,
                'position' => 99,
                'is_collapsed' => false,
                'is_triage' => false,
                'is_not_now' => false,
                'is_done' => false,
            ])
            ->assertRedirect($detailUrl);
    }

    expect($triage->fresh())
        ->name->toBe('受信箱')
        ->position->toBe($initialPositions[$triage->id])
        ->is_collapsed->toBeFalse()
        ->is_triage->toBeTrue()
        ->is_not_now->toBeFalse()
        ->is_done->toBeFalse();
    expect($notNow->fresh())
        ->name->toBe('保留')
        ->position->toBe($initialPositions[$notNow->id])
        ->is_collapsed->toBeTrue()
        ->is_triage->toBeFalse()
        ->is_not_now->toBeTrue()
        ->is_done->toBeFalse();
    expect($done->fresh())
        ->name->toBe('完了済み')
        ->position->toBe($initialPositions[$done->id])
        ->is_collapsed->toBeTrue()
        ->is_triage->toBeFalse()
        ->is_not_now->toBeFalse()
        ->is_done->toBeTrue();

    $this->get(route('projects.card-tables.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tables.0.triage.name', '受信箱')
            ->where('tables.0.notNow.name', '保留')
            ->where('tables.0.done.name', '完了済み'));

    foreach ([$triage, $notNow, $done] as $column) {
        $this->patch(route('projects.card-columns.move', [$team, $project, $cardTable, $column]), [
            'target_position' => 0,
        ])->assertNotFound();

        $this->patch(route('projects.card-columns.collapse', [$team, $project, $cardTable, $column]))
            ->assertNotFound();

        $this->delete(route('projects.card-columns.destroy', [$team, $project, $cardTable, $column]))
            ->assertNotFound();
    }
});

test('column renames without a referrer return to the board', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $column = CardColumn::factory()->for($cardTable)->create();

    $this->actingAs($owner)
        ->patch(route('projects.card-columns.update', [$team, $project, $cardTable, $column]), [
            'name' => '確認中',
        ])
        ->assertRedirect(route('projects.card-tables.index', [$team, $project]));

    expect($column->fresh()->name)->toBe('確認中');
});

test('card columns can be reordered with normalized positions', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $first = CardColumn::factory()->for($cardTable)->create(['position' => 2]);
    $second = CardColumn::factory()->for($cardTable)->create(['position' => 7]);
    $third = CardColumn::factory()->for($cardTable)->create(['position' => 11]);

    $this->actingAs($owner)
        ->patch(route('projects.card-columns.move', [$team, $project, $cardTable, $third]), [
            'target_position' => 0,
        ])
        ->assertRedirect();

    expect($third->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1)
        ->and($second->fresh()->position)->toBe(2);
});

test('large card column reorders stay normalized across update chunks', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $cardTable = CardTable::factory()->for($project)->create();
    $timestamp = now();

    collect(range(0, 250))->chunk(100)->each(function ($positions) use ($cardTable, $timestamp): void {
        CardColumn::query()->insert($positions->map(fn (int $position): array => [
            'card_table_id' => $cardTable->id,
            'name' => "Column {$position}",
            'position' => $position,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
    });

    $last = $cardTable->cardColumns()->orderByDesc('position')->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('projects.card-columns.move', [$team, $project, $cardTable, $last]), [
            'target_position' => 0,
        ])
        ->assertRedirect();

    $ordered = $cardTable->cardColumns()
        ->where('is_triage', false)
        ->where('is_not_now', false)
        ->where('is_done', false)
        ->orderBy('position')
        ->orderBy('id')
        ->get(['id', 'position']);

    expect($ordered->first()->id)->toBe($last->id)
        ->and($ordered->pluck('position')->all())->toBe(range(0, 250));
});
