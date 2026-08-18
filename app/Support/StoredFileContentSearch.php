<?php

namespace App\Support;

use App\Enums\StoredFileContentStatus;
use Illuminate\Database\Query\Builder;

final class StoredFileContentSearch
{
    public function __construct(private FullTextSearch $fullTextSearch) {}

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

            $this->fullTextSearch->apply(
                $contentQuery,
                FullTextSearch::STORED_FILE_CONTENTS,
                $search,
            );
        });
    }
}
