<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Card;
use App\Models\CardColumn;
use App\Models\CardTable;
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

    $this->post(route('projects.card-columns.store', [$team, $project, $cardTable]), ['name' => '未着手'])
        ->assertRedirect();

    $column = CardColumn::query()->firstOrFail();

    $this->post(route('projects.cards.store', [$team, $project, $cardTable, $column]), [
        'title' => 'モバイル表示を確認する',
        'description' => '390pxで横にはみ出さないこと',
        'assignee_id' => $member->id,
        'due_on' => '2026-08-01',
    ])->assertRedirect();

    $card = Card::query()->firstOrFail();

    expect($card)
        ->created_by->toBe($member->id)
        ->assignee_id->toBe($member->id)
        ->version->toBe(1);
    expect(Activity::query()->where('type', ActivityType::CardCreated)->exists())->toBeTrue();

    $this->get(route('projects.card-tables.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/card-tables/Index')
            ->where('tables.0.name', 'リリース準備')
            ->where('tables.0.canDelete', false)
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
        ->delete(route('projects.card-columns.destroy', [$team, $project, $cardTable, $column]))
        ->assertRedirect();
    $this->delete(route('projects.card-tables.destroy', [$team, $project, $cardTable]))
        ->assertRedirect();

    expect($cardTable->fresh())->toBeNull();
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

    $ordered = $cardTable->cardColumns()->orderBy('position')->orderBy('id')->get(['id', 'position']);

    expect($ordered->first()->id)->toBe($last->id)
        ->and($ordered->pluck('position')->all())->toBe(range(0, 250));
});
