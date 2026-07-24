export type CardTableColumnLayout<Column> = {
    expandedColumns: Column[];
    collapsedColumns: Column[];
};

export const cardTableColumnLayout = <Column extends { collapsed: boolean }>(
    columns: Column[],
): CardTableColumnLayout<Column> => {
    return {
        expandedColumns: columns.filter((column) => !column.collapsed),
        collapsedColumns: columns.filter((column) => column.collapsed),
    };
};
