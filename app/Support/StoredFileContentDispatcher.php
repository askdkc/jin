<?php

namespace App\Support;

use App\Enums\StoredFileContentFailure;
use App\Enums\StoredFileContentStatus;
use App\Jobs\ExtractStoredFileContent;
use App\Models\StoredFile;
use App\Models\StoredFileContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StoredFileContentDispatcher
{
    public function __construct(
        private StoredFileContentTypeResolver $typeResolver,
    ) {}

    public function prepareAndDispatch(StoredFile $storedFile, bool $force = false): bool
    {
        if (! $this->prepare($storedFile, $force)) {
            return false;
        }

        $checksum = $storedFile->checksum;

        if ($checksum === null) {
            return false;
        }

        $this->dispatchAfterCommit(
            $storedFile->id,
            $checksum,
            $storedFile->disk,
            $storedFile->path,
        );

        return true;
    }

    public function prepare(StoredFile $storedFile, bool $force = false): bool
    {
        if (! (bool) config('stored-file-content.enabled', false)) {
            return false;
        }

        $type = $this->typeResolver->resolve($storedFile);
        $checksum = $storedFile->checksum;
        $sourceChecksum = $checksum ?? str_repeat('0', 64);
        $existing = StoredFileContent::query()->find($storedFile->id);

        if (! $force
            && $existing !== null
            && $existing->source_checksum === $sourceChecksum) {
            return false;
        }

        $status = StoredFileContentStatus::Pending;
        $failure = null;
        $extractor = $type['extractor'] ?? null;

        if ($checksum === null) {
            $status = StoredFileContentStatus::Failed;
            $failure = StoredFileContentFailure::SourceMissing;
        } elseif ($type === null) {
            $status = StoredFileContentStatus::Unsupported;
            $failure = StoredFileContentFailure::UnsupportedFormat;
        } elseif ($storedFile->size > (int) config('stored-file-content.max_source_bytes')) {
            $status = StoredFileContentStatus::Failed;
            $failure = StoredFileContentFailure::SourceTooLarge;
        }

        StoredFileContent::query()->updateOrCreate(
            ['stored_file_id' => $storedFile->id],
            [
                'source_checksum' => $sourceChecksum,
                'status' => $status,
                'extractor' => $extractor,
                'content' => null,
                'content_bytes' => 0,
                'is_truncated' => false,
                'failure_code' => $failure,
                'processing_started_at' => null,
                'extracted_at' => null,
            ],
        );

        return $status === StoredFileContentStatus::Pending;
    }

    public function dispatchAfterCommit(
        int $storedFileId,
        string $sourceChecksum,
        string $sourceDisk,
        string $sourcePath,
    ): void {
        DB::afterCommit(static function () use ($sourceChecksum, $sourceDisk, $sourcePath, $storedFileId): void {
            try {
                ExtractStoredFileContent::dispatch(
                    $storedFileId,
                    $sourceChecksum,
                    $sourceDisk,
                    $sourcePath,
                );
            } catch (Throwable $exception) {
                StoredFileContent::query()
                    ->whereKey($storedFileId)
                    ->where('source_checksum', $sourceChecksum)
                    ->whereIn('status', [
                        StoredFileContentStatus::Pending->value,
                        StoredFileContentStatus::Processing->value,
                    ])
                    ->update([
                        'status' => StoredFileContentStatus::Failed->value,
                        'content' => null,
                        'content_bytes' => 0,
                        'is_truncated' => false,
                        'failure_code' => StoredFileContentFailure::DispatchFailed->value,
                        'processing_started_at' => null,
                        'extracted_at' => null,
                    ]);

                Log::error('Stored file content extraction could not be queued.', [
                    'stored_file_id' => $storedFileId,
                    'source_checksum_prefix' => substr($sourceChecksum, 0, 12),
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}
