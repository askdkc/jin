<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use App\Support\StoredFileDeletion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('stored-files:prune-inline')]
#[Description('Delete abandoned inline project files after a 24-hour grace period')]
class PruneInlineStoredFiles extends Command
{
    public function handle(StoredFileDeletion $deletion): int
    {
        $deleted = 0;
        $failed = 0;

        StoredFile::query()
            ->where('is_inline', true)
            ->whereNull('attachable_type')
            ->whereNull('attachable_id')
            ->where('created_at', '<', now()->subHours(24))
            ->select('id')
            ->eachById(function (StoredFile $file) use ($deletion, &$deleted, &$failed): void {
                try {
                    if ($deletion->deleteAbandonedInline($file->id)) {
                        $deleted++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('Abandoned inline file cleanup failed.', [
                        'stored_file_id' => $file->id,
                        'exception' => $exception,
                    ]);
                }
            }, 100);

        $this->components->info("Deleted {$deleted} abandoned inline file(s).");

        if ($failed > 0) {
            $this->components->warn("{$failed} inline file cleanup(s) will be retried later.");
        }

        return self::SUCCESS;
    }
}
