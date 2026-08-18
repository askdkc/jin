<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{
     *     table: string,
     *     row_column: string,
     *     columns: list<array{name: string, select: string, new: string, old: string}>
     * }>
     */
    private const SQLITE_INDEXES = [
        'message_board_posts_fts' => [
            'table' => 'message_board_posts',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'title', 'select' => 'title', 'new' => 'new.title', 'old' => 'old.title'],
                ['name' => 'body', 'select' => 'body', 'new' => 'new.body', 'old' => 'old.body'],
            ],
        ],
        'todos_fts' => [
            'table' => 'todos',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'title', 'select' => 'title', 'new' => 'new.title', 'old' => 'old.title'],
                ['name' => 'details', 'select' => 'details', 'new' => 'new.details', 'old' => 'old.details'],
            ],
        ],
        'chat_messages_fts' => [
            'table' => 'chat_messages',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'body', 'select' => 'body', 'new' => 'new.body', 'old' => 'old.body'],
            ],
        ],
        'documents_fts' => [
            'table' => 'documents',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'title', 'select' => 'title', 'new' => 'new.title', 'old' => 'old.title'],
                ['name' => 'body', 'select' => 'body', 'new' => 'new.body', 'old' => 'old.body'],
            ],
        ],
        'stored_files_fts' => [
            'table' => 'stored_files',
            'row_column' => 'id',
            'columns' => [
                [
                    'name' => 'original_name',
                    'select' => 'original_name',
                    'new' => 'new.original_name',
                    'old' => 'old.original_name',
                ],
            ],
        ],
        'external_links_fts' => [
            'table' => 'external_links',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'title', 'select' => 'title', 'new' => 'new.title', 'old' => 'old.title'],
                ['name' => 'url', 'select' => 'url', 'new' => 'new.url', 'old' => 'old.url'],
            ],
        ],
        'comments_fts' => [
            'table' => 'comments',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'body', 'select' => 'body', 'new' => 'new.body', 'old' => 'old.body'],
            ],
        ],
        'projects_fts' => [
            'table' => 'projects',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'name', 'select' => 'name', 'new' => 'new.name', 'old' => 'old.name'],
                ['name' => 'description', 'select' => 'description', 'new' => 'new.description', 'old' => 'old.description'],
            ],
        ],
        'users_fts' => [
            'table' => 'users',
            'row_column' => 'id',
            'columns' => [
                ['name' => 'name', 'select' => 'name', 'new' => 'new.name', 'old' => 'old.name'],
            ],
        ],
        'activities_fts' => [
            'table' => 'activities',
            'row_column' => 'id',
            'columns' => [
                [
                    'name' => 'label',
                    'select' => "json_extract(metadata, '$.label')",
                    'new' => "json_extract(new.metadata, '$.label')",
                    'old' => "json_extract(old.metadata, '$.label')",
                ],
            ],
        ],
    ];

    /**
     * @var list<array{name: string, table: string, expression: string, operator_class: string}>
     */
    private const POSTGRES_INDEXES = [
        [
            'name' => 'message_board_posts_title_pgroonga_index',
            'table' => 'message_board_posts',
            'expression' => 'title',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'message_board_posts_body_pgroonga_index',
            'table' => 'message_board_posts',
            'expression' => 'body',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'todos_title_pgroonga_index',
            'table' => 'todos',
            'expression' => 'title',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'todos_details_pgroonga_index',
            'table' => 'todos',
            'expression' => 'details',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'chat_messages_body_pgroonga_index',
            'table' => 'chat_messages',
            'expression' => 'body',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'documents_title_pgroonga_index',
            'table' => 'documents',
            'expression' => 'title',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'documents_body_pgroonga_index',
            'table' => 'documents',
            'expression' => 'body',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'stored_files_original_name_pgroonga_index',
            'table' => 'stored_files',
            'expression' => 'original_name',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'external_links_title_pgroonga_index',
            'table' => 'external_links',
            'expression' => 'title',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'external_links_url_pgroonga_index',
            'table' => 'external_links',
            'expression' => 'url',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'comments_body_pgroonga_index',
            'table' => 'comments',
            'expression' => 'body',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'projects_name_pgroonga_index',
            'table' => 'projects',
            'expression' => 'name',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'projects_description_pgroonga_index',
            'table' => 'projects',
            'expression' => 'description',
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
        [
            'name' => 'users_name_pgroonga_index',
            'table' => 'users',
            'expression' => 'name',
            'operator_class' => 'pgroonga_varchar_full_text_search_ops_v2',
        ],
        [
            'name' => 'activities_label_pgroonga_index',
            'table' => 'activities',
            'expression' => "(metadata->>'label')",
            'operator_class' => 'pgroonga_text_full_text_search_ops_v2',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->createSqliteIndexes(),
            'pgsql' => $this->createPostgresIndexes(),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->dropSqliteIndexes(),
            'pgsql' => $this->dropPostgresIndexes(),
            default => null,
        };
    }

    private function createSqliteIndexes(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO stored_file_contents_fts(stored_file_contents_fts)
            VALUES ('rebuild')
        SQL);

        foreach (self::SQLITE_INDEXES as $ftsTable => $definition) {
            $columnNames = implode(', ', array_column($definition['columns'], 'name'));
            $selectColumns = implode(', ', array_map(
                fn (array $column): string => "{$column['select']} AS {$column['name']}",
                $definition['columns'],
            ));
            $newValues = implode(', ', array_column($definition['columns'], 'new'));
            $oldValues = implode(', ', array_column($definition['columns'], 'old'));
            $updateColumns = $ftsTable === 'activities_fts'
                ? 'metadata'
                : $columnNames;

            DB::statement(<<<SQL
                CREATE VIRTUAL TABLE {$ftsTable} USING fts5(
                    {$columnNames},
                    content = '{$definition['table']}',
                    content_rowid = '{$definition['row_column']}',
                    tokenize = 'trigram case_sensitive 0'
                )
            SQL);

            DB::statement(<<<SQL
                INSERT INTO {$ftsTable}(rowid, {$columnNames})
                SELECT {$definition['row_column']}, {$selectColumns}
                FROM {$definition['table']}
            SQL);

            DB::statement(<<<SQL
                CREATE TRIGGER {$ftsTable}_insert
                AFTER INSERT ON {$definition['table']}
                BEGIN
                    INSERT INTO {$ftsTable}(rowid, {$columnNames})
                    VALUES (new.{$definition['row_column']}, {$newValues});
                END
            SQL);

            DB::statement(<<<SQL
                CREATE TRIGGER {$ftsTable}_update
                AFTER UPDATE OF {$updateColumns} ON {$definition['table']}
                BEGIN
                    INSERT INTO {$ftsTable}({$ftsTable}, rowid, {$columnNames})
                    VALUES ('delete', old.{$definition['row_column']}, {$oldValues});

                    INSERT INTO {$ftsTable}(rowid, {$columnNames})
                    VALUES (new.{$definition['row_column']}, {$newValues});
                END
            SQL);

            DB::statement(<<<SQL
                CREATE TRIGGER {$ftsTable}_delete
                AFTER DELETE ON {$definition['table']}
                BEGIN
                    INSERT INTO {$ftsTable}({$ftsTable}, rowid, {$columnNames})
                    VALUES ('delete', old.{$definition['row_column']}, {$oldValues});
                END
            SQL);
        }
    }

    private function dropSqliteIndexes(): void
    {
        foreach (array_reverse(array_keys(self::SQLITE_INDEXES)) as $ftsTable) {
            DB::statement("DROP TRIGGER IF EXISTS {$ftsTable}_delete");
            DB::statement("DROP TRIGGER IF EXISTS {$ftsTable}_update");
            DB::statement("DROP TRIGGER IF EXISTS {$ftsTable}_insert");
            DB::statement("DROP TABLE IF EXISTS {$ftsTable}");
        }
    }

    private function createPostgresIndexes(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgroonga');

        foreach (self::POSTGRES_INDEXES as $index) {
            DB::statement(<<<SQL
                CREATE INDEX {$index['name']}
                ON {$index['table']}
                USING pgroonga ({$index['expression']} {$index['operator_class']})
            SQL);
        }
    }

    private function dropPostgresIndexes(): void
    {
        foreach (array_reverse(self::POSTGRES_INDEXES) as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index['name']}");
        }
    }
};
