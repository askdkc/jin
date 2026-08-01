export type CardTableColumnLayoutItem<Column> = {
    column: Column;
    state: 'expanded' | 'collapsed';
};

export const cardTableColumnLayout = <Column extends { collapsed: boolean }>(
    columns: Column[],
): CardTableColumnLayoutItem<Column>[] =>
    columns.map((column) => ({
        column,
        state: column.collapsed ? 'collapsed' : 'expanded',
    }));
