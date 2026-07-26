<?php

namespace App\Support;

use App\Enums\StoredFileContentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StoredFileContentSearch
{
    public function addContentMatch(Builder $query, string $search): void
    {
        $query->orWhereExists(function (Builder $contentQuery) use ($search): void {
            $contentQuery
                ->selectRaw('1')
                ->from('stored_file_contents')
                ->whereColumn('stored_file_contents.stored_file_id', 'stored_files.id')
                ->whereColumn('stored_file_contents.source_checksum', 'stored_files.checksum')
                ->where('stored_file_contents.status', StoredFileContentStatus::Ready->value)
                ->where('stored_file_contents.extractor', 'markitdown')
                ->whereNotNull('stored_file_contents.content');

            match (DB::connection()->getDriverName()) {
                'sqlite' => $this->applySqliteSearch($contentQuery, $search),
                'pgsql' => $this->applyPostgresSearch($contentQuery, $search),
                default => $this->applyPortableSearch($contentQuery, $search),
            };
        });
    }

    private function applySqliteSearch(Builder $query, string $search): void
    {
        if (mb_strlen($search) < 3) {
            $query->whereRaw(
                'INSTR(LOWER(stored_file_contents.content), LOWER(?)) > 0',
                [$search],
            );

            return;
        }

        $query->whereExists(function (Builder $ftsQuery) use ($search): void {
            $ftsQuery
                ->selectRaw('1')
                ->from('stored_file_contents_fts')
                ->whereColumn('stored_file_contents_fts.rowid', 'stored_file_contents.stored_file_id')
                ->whereRaw('stored_file_contents_fts MATCH ?', [$this->sqlitePhrase($search)]);
        });
    }

    private function applyPostgresSearch(Builder $query, string $search): void
    {
        $query->whereRaw(
            <<<'SQL'
                stored_file_contents.content &@~ ('"' || pgroonga_query_escape(?) || '"')
            SQL,
            [$search],
        );
    }

    private function applyPortableSearch(Builder $query, string $search): void
    {
        $query->whereRaw(
            "LOWER(COALESCE(stored_file_contents.content, '')) LIKE LOWER(?) ESCAPE '!'",
            [$this->literalLikePattern($search)],
        );
    }

    private function sqlitePhrase(string $search): string
    {
        return '"'.str_replace('"', '""', $search).'"';
    }

    private function literalLikePattern(string $search): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $search,
        ).'%';
    }
}
