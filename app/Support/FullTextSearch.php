<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class FullTextSearch
{
    public const MESSAGE_BOARD_POSTS = 'message_board_posts';

    public const TODOS = 'todos';

    public const CHAT_MESSAGES = 'chat_messages';

    public const DOCUMENTS = 'documents';

    public const STORED_FILES = 'stored_files';

    public const EXTERNAL_LINKS = 'external_links';

    public const COMMENTS = 'comments';

    public const PROJECTS = 'projects';

    public const USERS = 'users';

    public const ACTIVITIES = 'activities';

    public const STORED_FILE_CONTENTS = 'stored_file_contents';

    /**
     * @var array<string, array{
     *     base_table: literal-string,
     *     row_column: literal-string,
     *     fts_table: literal-string,
     *     columns: list<array{sqlite: literal-string, postgres: literal-string, postgres_type: 'text'|'varchar'}>
     * }>
     */
    private const TARGETS = [
        self::MESSAGE_BOARD_POSTS => [
            'base_table' => 'message_board_posts',
            'row_column' => 'id',
            'fts_table' => 'message_board_posts_fts',
            'columns' => [
                ['sqlite' => 'message_board_posts.title', 'postgres' => 'message_board_posts.title', 'postgres_type' => 'varchar'],
                ['sqlite' => 'message_board_posts.body', 'postgres' => 'message_board_posts.body', 'postgres_type' => 'text'],
            ],
        ],
        self::TODOS => [
            'base_table' => 'todos',
            'row_column' => 'id',
            'fts_table' => 'todos_fts',
            'columns' => [
                ['sqlite' => 'todos.title', 'postgres' => 'todos.title', 'postgres_type' => 'varchar'],
                ['sqlite' => 'todos.details', 'postgres' => 'todos.details', 'postgres_type' => 'text'],
            ],
        ],
        self::CHAT_MESSAGES => [
            'base_table' => 'chat_messages',
            'row_column' => 'id',
            'fts_table' => 'chat_messages_fts',
            'columns' => [
                ['sqlite' => 'chat_messages.body', 'postgres' => 'chat_messages.body', 'postgres_type' => 'text'],
            ],
        ],
        self::DOCUMENTS => [
            'base_table' => 'documents',
            'row_column' => 'id',
            'fts_table' => 'documents_fts',
            'columns' => [
                ['sqlite' => 'documents.title', 'postgres' => 'documents.title', 'postgres_type' => 'varchar'],
                ['sqlite' => 'documents.body', 'postgres' => 'documents.body', 'postgres_type' => 'text'],
            ],
        ],
        self::STORED_FILES => [
            'base_table' => 'stored_files',
            'row_column' => 'id',
            'fts_table' => 'stored_files_fts',
            'columns' => [
                ['sqlite' => 'stored_files.original_name', 'postgres' => 'stored_files.original_name', 'postgres_type' => 'varchar'],
            ],
        ],
        self::EXTERNAL_LINKS => [
            'base_table' => 'external_links',
            'row_column' => 'id',
            'fts_table' => 'external_links_fts',
            'columns' => [
                ['sqlite' => 'external_links.title', 'postgres' => 'external_links.title', 'postgres_type' => 'varchar'],
                ['sqlite' => 'external_links.url', 'postgres' => 'external_links.url', 'postgres_type' => 'varchar'],
            ],
        ],
        self::COMMENTS => [
            'base_table' => 'comments',
            'row_column' => 'id',
            'fts_table' => 'comments_fts',
            'columns' => [
                ['sqlite' => 'comments.body', 'postgres' => 'comments.body', 'postgres_type' => 'text'],
            ],
        ],
        self::PROJECTS => [
            'base_table' => 'projects',
            'row_column' => 'id',
            'fts_table' => 'projects_fts',
            'columns' => [
                ['sqlite' => 'projects.name', 'postgres' => 'projects.name', 'postgres_type' => 'varchar'],
                ['sqlite' => 'projects.description', 'postgres' => 'projects.description', 'postgres_type' => 'text'],
            ],
        ],
        self::USERS => [
            'base_table' => 'users',
            'row_column' => 'id',
            'fts_table' => 'users_fts',
            'columns' => [
                ['sqlite' => 'users.name', 'postgres' => 'users.name', 'postgres_type' => 'varchar'],
            ],
        ],
        self::ACTIVITIES => [
            'base_table' => 'activities',
            'row_column' => 'id',
            'fts_table' => 'activities_fts',
            'columns' => [
                [
                    'sqlite' => "json_extract(activities.metadata, '$.label')",
                    'postgres' => "activities.metadata->>'label'",
                    'postgres_type' => 'text',
                ],
            ],
        ],
        self::STORED_FILE_CONTENTS => [
            'base_table' => 'stored_file_contents',
            'row_column' => 'stored_file_id',
            'fts_table' => 'stored_file_contents_fts',
            'columns' => [
                ['sqlite' => 'stored_file_contents.content', 'postgres' => 'stored_file_contents.content', 'postgres_type' => 'text'],
            ],
        ],
    ];

    public function __construct(private readonly ?string $configuredDriver = null) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder|EloquentBuilder<TModel>  $query
     */
    public function apply(
        Builder|EloquentBuilder $query,
        string $target,
        string $search,
    ): void {
        $definition = self::TARGETS[$target] ?? throw new InvalidArgumentException(
            "Unknown full-text search target [{$target}].",
        );
        $driver = $this->configuredDriver ?? DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new LogicException(
                "Full-text search is not supported for database driver [{$driver}].",
            );
        }

        if ($search === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($driver === 'sqlite') {
            $this->applySqliteSearch($query, $definition, $search);

            return;
        }

        $this->applyPostgresSearch($query, $definition, $search);
    }

    /**
     * @template TModel of Model
     *
     * @param array{
     *     base_table: literal-string,
     *     row_column: literal-string,
     *     fts_table: literal-string,
     *     columns: list<array{sqlite: literal-string, postgres: literal-string, postgres_type: 'text'|'varchar'}>
     * } $definition
     * @param  Builder|EloquentBuilder<TModel>  $query
     */
    private function applySqliteSearch(
        Builder|EloquentBuilder $query,
        array $definition,
        string $search,
    ): void {
        if (mb_strlen($search) < 3 || str_contains($search, "\0")) {
            $query->where(function (Builder|EloquentBuilder $searchQuery) use ($definition, $search): void {
                foreach ($definition['columns'] as $index => $column) {
                    $condition = "INSTR(LOWER(COALESCE({$column['sqlite']}, '')), LOWER(?)) > 0";

                    if ($index === 0) {
                        $searchQuery->whereRaw($condition, [$search]);
                    } else {
                        $searchQuery->orWhereRaw($condition, [$search]);
                    }
                }
            });

            return;
        }

        $query->whereExists(function (Builder $ftsQuery) use ($definition, $search): void {
            $ftsQuery
                ->selectRaw('1')
                ->from($definition['fts_table'])
                ->whereColumn(
                    "{$definition['fts_table']}.rowid",
                    "{$definition['base_table']}.{$definition['row_column']}",
                )
                ->whereRaw(
                    "{$definition['fts_table']} MATCH ?",
                    [$this->sqlitePhrase($search)],
                );
        });
    }

    /**
     * @template TModel of Model
     *
     * @param array{
     *     base_table: literal-string,
     *     row_column: literal-string,
     *     fts_table: literal-string,
     *     columns: list<array{sqlite: literal-string, postgres: literal-string, postgres_type: 'text'|'varchar'}>
     * } $definition
     * @param  Builder|EloquentBuilder<TModel>  $query
     */
    private function applyPostgresSearch(
        Builder|EloquentBuilder $query,
        array $definition,
        string $search,
    ): void {
        if (str_contains($search, "\0")) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder|EloquentBuilder $searchQuery) use ($definition, $search): void {
            foreach ($definition['columns'] as $index => $column) {
                $condition = $this->postgresCondition($column);

                if ($index === 0) {
                    $searchQuery->whereRaw($condition, [$search]);
                } else {
                    $searchQuery->orWhereRaw($condition, [$search]);
                }
            }
        });
    }

    /**
     * @param  array{sqlite: literal-string, postgres: literal-string, postgres_type: 'text'|'varchar'}  $column
     * @return literal-string
     */
    private function postgresCondition(array $column): string
    {
        $escapedQuery = <<<'SQL'
            ('"' || pgroonga_query_escape(?) || '"')
        SQL;

        if ($column['postgres_type'] === 'varchar') {
            $escapedQuery = "CAST({$escapedQuery} AS varchar)";
        }

        return "{$column['postgres']} &@~ {$escapedQuery}";
    }

    private function sqlitePhrase(string $search): string
    {
        return '"'.str_replace('"', '""', $search).'"';
    }
}
