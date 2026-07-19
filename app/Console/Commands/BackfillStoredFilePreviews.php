<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use App\Support\StoredFilePreviewDispatcher;
use App\Support\StoredFilePreviewGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('stored-files:backfill-previews {--chunk=200}')]
#[Description('Queue previews for legacy stored files')]
class BackfillStoredFilePreviews extends Command
{
    public function handle(StoredFilePreviewDispatcher $dispatcher): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunk === false || $chunk < 1) {
            $this->components->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $chunk = min($chunk, 1000);
        $queuedCount = 0;

        StoredFile::query()
            ->where(static function (Builder $query): void {
                $query->whereNull('preview_status')
                    ->orWhere('preview_status', 'failed');
            })
            ->select(['id', 'path', 'original_name'])
            ->chunkById($chunk, function (Collection $storedFiles) use (&$queuedCount, $dispatcher): void {
                foreach ($storedFiles as $storedFile) {
                    if (! StoredFilePreviewGenerator::supports($storedFile->original_name)) {
                        continue;
                    }

                    $claimed = DB::transaction(function () use ($dispatcher, $storedFile): bool {
                        $updated = StoredFile::query()
                            ->whereKey($storedFile->id)
                            ->where('path', $storedFile->path)
                            ->where(static function (Builder $query): void {
                                $query->whereNull('preview_status')
                                    ->orWhere('preview_status', 'failed');
                            })
                            ->update([
                                'preview_path' => null,
                                'preview_status' => 'pending',
                            ]);

                        if ($updated === 1) {
                            $dispatcher->dispatchAfterCommit($storedFile->id, $storedFile->path);
                        }

                        return $updated === 1;
                    });

                    if ($claimed) {
                        $queuedCount++;
                    }
                }
            });

        $this->components->info("Scheduled {$queuedCount} stored file previews.");

        return self::SUCCESS;
    }
}
