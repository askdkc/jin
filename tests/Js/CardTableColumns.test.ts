import assert from 'node:assert/strict';
import test from 'node:test';
import { cardTableColumnLayout } from '../../resources/js/lib/card-table-columns.ts';

test('collapsing the first column keeps it in the collapsed column strip', () => {
    const firstColumn = { id: 1, collapsed: false };

    assert.deepEqual(cardTableColumnLayout([firstColumn]), {
        expandedColumns: [firstColumn],
        collapsedColumns: [],
    });

    const collapsedFirstColumn = { ...firstColumn, collapsed: true };

    assert.deepEqual(cardTableColumnLayout([collapsedFirstColumn]), {
        expandedColumns: [],
        collapsedColumns: [collapsedFirstColumn],
    });
});

test('all user-created columns remain in the board layout', () => {
    const firstColumn = { id: 1, collapsed: false };
    const expandedColumn = { id: 2, collapsed: false };
    const collapsedColumn = { id: 3, collapsed: true };

    assert.deepEqual(
        cardTableColumnLayout([firstColumn, expandedColumn, collapsedColumn]),
        {
            expandedColumns: [firstColumn, expandedColumn],
            collapsedColumns: [collapsedColumn],
        },
    );
});
