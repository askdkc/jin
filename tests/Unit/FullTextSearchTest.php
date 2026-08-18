<?php

use App\Support\FullTextSearch;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;

function fullTextSearchQueryBuilder(string $driver): Builder
{
    $connection = $driver === 'pgsql'
        ? new PostgresConnection(new PDO('sqlite::memory:'))
        : new SQLiteConnection(new PDO('sqlite::memory:'));

    return $connection->table('documents');
}

test('sqlite uses a quoted FTS5 phrase for terms of three or more characters', function () {
    $query = fullTextSearchQueryBuilder('sqlite');

    (new FullTextSearch('sqlite'))->apply(
        $query,
        FullTextSearch::DOCUMENTS,
        'price_% "quoted"',
    );

    expect($query->toSql())
        ->toContain('documents_fts MATCH ?')
        ->and($query->getBindings())->toBe(['"price_% ""quoted"""']);
});

test('sqlite falls back to instr for one and two character terms', function () {
    $query = fullTextSearchQueryBuilder('sqlite');

    (new FullTextSearch('sqlite'))->apply($query, FullTextSearch::DOCUMENTS, '法');

    expect($query->toSql())
        ->toContain("INSTR(LOWER(COALESCE(documents.title, '')), LOWER(?)) > 0")
        ->toContain("INSTR(LOWER(COALESCE(documents.body, '')), LOWER(?)) > 0")
        ->and($query->getBindings())->toBe(['法', '法']);
});

test('postgresql uses escaped literal pgroonga conditions for every target column', function () {
    $query = fullTextSearchQueryBuilder('pgsql');

    (new FullTextSearch('pgsql'))->apply(
        $query,
        FullTextSearch::EXTERNAL_LINKS,
        'url_% "quoted"',
    );

    expect($query->toSql())
        ->toContain('external_links.title &@~')
        ->toContain('external_links.url &@~')
        ->toContain('pgroonga_query_escape(?)')
        ->and($query->getBindings())->toBe(['url_% "quoted"', 'url_% "quoted"']);
});

test('activity search uses the fixed json label expression', function () {
    $query = fullTextSearchQueryBuilder('sqlite');

    (new FullTextSearch('sqlite'))->apply($query, FullTextSearch::ACTIVITIES, 'ラ');

    expect($query->toSql())
        ->toContain("json_extract(activities.metadata, '$.label')")
        ->not->toContain('LIKE');
});

test('unsupported database drivers fail instead of falling back to like', function () {
    $query = fullTextSearchQueryBuilder('mysql');

    expect(fn () => (new FullTextSearch('mysql'))->apply(
        $query,
        FullTextSearch::DOCUMENTS,
        'search term',
    ))
        ->toThrow(LogicException::class, 'Full-text search is not supported for database driver [mysql].');
});

test('unknown search targets are rejected', function () {
    $query = fullTextSearchQueryBuilder('sqlite');

    expect(fn () => (new FullTextSearch('sqlite'))->apply($query, 'custom_table', 'term'))
        ->toThrow(InvalidArgumentException::class);
});
