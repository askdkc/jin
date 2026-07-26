<?php

namespace App\Console\Commands;

use App\Enums\StoredFileContentStatus;
use App\Models\StoredFile;
use App\Support\StoredFileContentDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('stored-files:backfill-content
    {--retry-failed : Retry files whose current extraction failed}
    {--reindex : Re-extract all supported files with the current extractors}')]
#[Description('Queue content extraction for existing stored files')]
class BackfillStoredFileContents extends Command
{
    public function handle(StoredFileContentDispatcher $dispatcher): int
    {
        if (! (bool) config('stored-file-content.enabled', false)) {
            $this->components->error('Stored file content extraction is disabled.');

            return self::FAILURE;
        }

        $retryFailed = (bool) $this->option('retry-failed');
        $reindex = (bool) $this->option('reindex');
        $queued = 0;
        $skipped = 0;

        StoredFile::query()
            ->with('contentSearchIndex')
            ->chunkById(100, function (Collection $storedFiles) use (
                $dispatcher,
                $reindex,
                $retryFailed,
                &$queued,
                &$skipped,
            ): void {
                foreach ($storedFiles as $storedFile) {
                    $content = $storedFile->contentSearchIndex;
                    $sourceChecksum = $storedFile->checksum ?? str_repeat('0', 64);
                    $isMissingOrStale = $content === null
                        || $content->source_checksum !== $sourceChecksum;
                    $shouldRetry = $retryFailed
                        && $content?->status === StoredFileContentStatus::Failed;

                    if (! $reindex && ! $isMissingOrStale && ! $shouldRetry) {
                        $skipped++;

                        continue;
                    }

                    if ($dispatcher->prepareAndDispatch(
                        $storedFile,
                        force: $reindex || $shouldRetry,
                    )) {
                        $queued++;
                    } else {
                        $skipped++;
                    }
                }
            });

        $this->components->info("Queued {$queued} file(s); skipped {$skipped} file(s).");

        return self::SUCCESS;
    }
}
