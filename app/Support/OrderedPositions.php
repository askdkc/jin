<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class OrderedPositions
{
    private const UPDATE_CHUNK_SIZE = 250;

    /** @param iterable<int, int|string> $ids */
    public static function resequence(Model $model, iterable $ids): void
    {
        $ids = collect($ids)
            ->map(fn (int|string $id): int => (int) $id)
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($model->getTable());
        $idColumn = $grammar->wrap($model->getKeyName());
        $positionColumn = $grammar->wrap('position');
        $updatedAtColumn = $grammar->wrap($model->getUpdatedAtColumn());

        foreach ($ids->chunk(self::UPDATE_CHUNK_SIZE) as $chunk) {
            $caseClauses = implode(' ', array_fill(0, $chunk->count(), 'WHEN ? THEN ?'));
            $wherePlaceholders = implode(', ', array_fill(0, $chunk->count(), '?'));

            // Bind every value: only model-derived, grammar-quoted identifiers enter SQL.
            // CASE is update-only, so a concurrent delete cannot recreate a row.
            $sql = "UPDATE {$table} SET {$positionColumn} = CASE {$idColumn} {$caseClauses} ELSE {$positionColumn} END, {$updatedAtColumn} = ? WHERE {$idColumn} IN ({$wherePlaceholders})";
            $caseBindings = $chunk
                ->flatMap(fn (int $id, int $position): array => [$id, $position])
                ->values()
                ->all();

            $connection->update($sql, [
                ...$caseBindings,
                now(),
                ...$chunk->values()->all(),
            ]);
        }
    }
}
