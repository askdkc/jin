import assert from 'node:assert/strict';
import test from 'node:test';
import { cardTableColumnLayout } from '../../resources/js/lib/card-table-columns.ts';

test('collapsing a column changes its layout state', () => {
    const firstColumn = { id: 1, collapsed: false };

    assert.deepEqual(cardTableColumnLayout([firstColumn]), [
        { column: firstColumn, state: 'expanded' },
    ]);

    const collapsedFirstColumn = { ...firstColumn, collapsed: true };

    assert.deepEqual(cardTableColumnLayout([collapsedFirstColumn]), [
        { column: collapsedFirstColumn, state: 'collapsed' },
    ]);
});

test('collapsed columns remain in their original board position', () => {
    const firstColumn = { id: 1, collapsed: false };
    const collapsedColumn = { id: 2, collapsed: true };
    const lastColumn = { id: 3, collapsed: false };

    assert.deepEqual(
        cardTableColumnLayout([firstColumn, collapsedColumn, lastColumn]),
        [
            { column: firstColumn, state: 'expanded' },
            { column: collapsedColumn, state: 'collapsed' },
            { column: lastColumn, state: 'expanded' },
        ],
    );
});
