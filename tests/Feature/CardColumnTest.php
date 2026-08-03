<?php

use App\Models\CardColumn;
use App\Models\CardTable;

test('non system scope retains ordinary columns and excludes system columns', function () {
    $cardTable = CardTable::factory()->create();
    $ordinary = CardColumn::factory()->for($cardTable)->create(['name' => 'Ordinary']);

    $columnIds = CardColumn::query()->nonSystem()->pluck('id');

    expect($columnIds)
        ->toContain($ordinary->id)
        ->not->toContain($cardTable->cardColumns()->where('is_triage', true)->sole()->id)
        ->not->toContain($cardTable->cardColumns()->where('is_not_now', true)->sole()->id)
        ->not->toContain($cardTable->cardColumns()->where('is_done', true)->sole()->id);
});

test('system classification recognizes reserved column flags', function () {
    expect((new CardColumn)->isSystem())->toBeFalse()
        ->and((new CardColumn(['is_triage' => true]))->isSystem())->toBeTrue()
        ->and((new CardColumn(['is_not_now' => true]))->isSystem())->toBeTrue()
        ->and((new CardColumn(['is_done' => true]))->isSystem())->toBeTrue();
});
