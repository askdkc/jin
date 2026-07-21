<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('stored-files:backfill-checksums {--chunk=200}')]
#[Description('Calculate missing checksums and byte sizes for stored files')]
class BackfillStoredFileChecksums extends Command
{
    public function handle(): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunk === false || $chunk < 1) {
            $this->components->error('The chunk size must be a positive integer.');

            return self::FAILURE;
        }

        $updated = 0;
        $skipped = 0;

        StoredFile::query()
            ->whereNull('checksum')
            ->select(['id', 'disk', 'path'])
            ->chunkById(min($chunk, 1000), function (Collection $storedFiles) use (&$skipped, &$updated): void {
                foreach ($storedFiles as $storedFile) {
                    try {
                        $disk = Storage::disk($storedFile->disk);

                        if (! $disk->exists($storedFile->path)) {
                            $skipped++;

                            continue;
                        }

                        $updated += StoredFile::query()
                            ->whereKey($storedFile->id)
                            ->whereNull('checksum')
                            ->where('disk', $storedFile->disk)
                            ->where('path', $storedFile->path)
                            ->update([
                                'checksum' => StoredFile::checksumFor($disk, $storedFile->path),
                                'size' => $disk->size($storedFile->path),
                            ]);
                    } catch (Throwable) {
                        $skipped++;
                    }
                }
            });

        $this->components->info("Updated {$updated} stored file checksums; skipped {$skipped} missing or unreadable files.");

        return $skipped === 0 ? self::SUCCESS : self::FAILURE;
    }
}
