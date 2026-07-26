<?php

namespace App\Jobs;

use App\Enums\StoredFileContentFailure;
use App\Enums\StoredFileContentStatus;
use App\Exceptions\StoredFileContentExtractionException;
use App\Models\StoredFile;
use App\Models\StoredFileContent;
use App\Support\StoredFileContentExtractionResult;
use App\Support\StoredFileContentExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractStoredFileContent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public bool $failOnTimeout = true;

    public function __construct(
        public int $storedFileId,
        public string $sourceChecksum,
        public string $sourceDisk,
        public string $sourcePath,
    ) {
        $this->timeout = (int) config('stored-file-content.job_timeout', 180);
        $this->onQueue((string) config('stored-file-content.queue', 'file-content'));
        $this->afterCommit = true;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping(
                $this->storedFileId.'-'.substr($this->sourceChecksum, 0, 16),
                releaseAfter: 90,
                expiresAfter: $this->timeout + 60,
            ),
        ];
    }

    public function handle(StoredFileContentExtractor $extractor): void
    {
        if (! $this->sourceIsCurrent()) {
            return;
        }

        $leaseCutoff = now()->subSeconds(
            (int) config('stored-file-content.processing_lease_seconds', 300),
        );
        $claimed = StoredFileContent::query()
            ->whereKey($this->storedFileId)
            ->where('source_checksum', $this->sourceChecksum)
            ->where(function (Builder $query) use ($leaseCutoff): void {
                $query->where('status', StoredFileContentStatus::Pending->value)
                    ->orWhere(function (Builder $processingQuery) use ($leaseCutoff): void {
                        $processingQuery
                            ->where('status', StoredFileContentStatus::Processing->value)
                            ->where('processing_started_at', '<=', $leaseCutoff);
                    });
            })
            ->update([
                'status' => StoredFileContentStatus::Processing->value,
                'content' => null,
                'content_bytes' => 0,
                'is_truncated' => false,
                'failure_code' => null,
                'processing_started_at' => now(),
                'extracted_at' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $storedFile = $this->currentStoredFile();

        if ($storedFile === null) {
            return;
        }

        try {
            $result = $extractor->extract($storedFile, $this->sourceChecksum);
        } catch (StoredFileContentExtractionException $exception) {
            if (! $exception->retryable) {
                $this->publishResult(StoredFileContentExtractionResult::failed(
                    $storedFile->contentSearchIndex->extractor ?? 'unknown',
                    $exception->failure,
                ));

                return;
            }

            $this->resetPendingForRetry();

            Log::warning('Stored file content extraction attempt failed.', [
                'stored_file_id' => $this->storedFileId,
                'failure_code' => $exception->failure->value,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $this->publishResult($result);
    }

    public function failed(?Throwable $exception): void
    {
        $failure = $exception instanceof StoredFileContentExtractionException
            ? $exception->failure
            : StoredFileContentFailure::ProcessFailed;

        StoredFileContent::query()
            ->whereKey($this->storedFileId)
            ->where('source_checksum', $this->sourceChecksum)
            ->whereHas('storedFile', function (Builder $query): void {
                $query->where('checksum', $this->sourceChecksum)
                    ->where('disk', $this->sourceDisk)
                    ->where('path', $this->sourcePath);
            })
            ->whereIn('status', [
                StoredFileContentStatus::Pending->value,
                StoredFileContentStatus::Processing->value,
            ])
            ->update([
                'status' => StoredFileContentStatus::Failed->value,
                'content' => null,
                'content_bytes' => 0,
                'is_truncated' => false,
                'failure_code' => $failure->value,
                'processing_started_at' => null,
                'extracted_at' => now(),
            ]);
    }

    private function publishResult(StoredFileContentExtractionResult $result): bool
    {
        return DB::transaction(function () use ($result): bool {
            $storedFile = StoredFile::query()
                ->whereKey($this->storedFileId)
                ->where('checksum', $this->sourceChecksum)
                ->where('disk', $this->sourceDisk)
                ->where('path', $this->sourcePath)
                ->lockForUpdate()
                ->first();
            $content = StoredFileContent::query()
                ->whereKey($this->storedFileId)
                ->where('source_checksum', $this->sourceChecksum)
                ->where('status', StoredFileContentStatus::Processing->value)
                ->lockForUpdate()
                ->first();

            if ($storedFile === null || $content === null) {
                return false;
            }

            return $content->update([
                'status' => $result->status,
                'extractor' => $result->extractor,
                'content' => $result->content,
                'content_bytes' => $result->contentBytes,
                'is_truncated' => $result->isTruncated,
                'failure_code' => $result->failure,
                'processing_started_at' => null,
                'extracted_at' => now(),
            ]);
        });
    }

    private function resetPendingForRetry(): void
    {
        StoredFileContent::query()
            ->whereKey($this->storedFileId)
            ->where('source_checksum', $this->sourceChecksum)
            ->where('status', StoredFileContentStatus::Processing->value)
            ->update([
                'status' => StoredFileContentStatus::Pending->value,
                'processing_started_at' => null,
            ]);
    }

    private function sourceIsCurrent(): bool
    {
        return $this->currentStoredFile() !== null;
    }

    private function currentStoredFile(): ?StoredFile
    {
        return StoredFile::query()
            ->whereKey($this->storedFileId)
            ->where('checksum', $this->sourceChecksum)
            ->where('disk', $this->sourceDisk)
            ->where('path', $this->sourcePath)
            ->first();
    }
}
