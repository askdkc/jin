<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoredFileDeletion
{
    private const LEASE_SECONDS = 60;

    public function __construct(private StoredFilePreviewDispatcher $previews) {}

    public function delete(int $storedFileId): bool
    {
        $deletion = $this->claim($storedFileId);

        if ($deletion === null) {
            return false;
        }

        $disk = Storage::disk($deletion['disk']);

        try {
            if ($disk->exists($deletion['path']) && ! $disk->delete($deletion['path'])) {
                if (Storage::disk($deletion['disk'])->exists($deletion['path'])) {
                    throw new RuntimeException('File deletion failed.');
                }
            }

            StoredFilePreviewGenerator::cleanup(
                $disk,
                $storedFileId,
                $deletion['path'],
                $deletion['preview_path'],
                includeLegacy: true,
            );
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($storedFileId, $deletion);

            throw $exception;
        }

        return $this->finalize($storedFileId, $deletion);
    }

    public function deleteAbandonedInline(int $storedFileId): bool
    {
        $deletion = $this->claim($storedFileId, abandonedInlineOnly: true);

        if ($deletion === null) {
            return false;
        }

        $disk = Storage::disk($deletion['disk']);

        try {
            if ($disk->exists($deletion['path']) && ! $disk->delete($deletion['path'])) {
                if (Storage::disk($deletion['disk'])->exists($deletion['path'])) {
                    throw new RuntimeException('File deletion failed.');
                }
            }

            StoredFilePreviewGenerator::cleanup(
                $disk,
                $storedFileId,
                $deletion['path'],
                $deletion['preview_path'],
                includeLegacy: true,
            );
        } catch (Throwable $exception) {
            $this->restoreAfterFailure($storedFileId, $deletion);

            throw $exception;
        }

        return $this->finalize($storedFileId, $deletion);
    }

    /** @return array{disk: string, path: string, original_name: string, preview_path: string|null, preview_status: string|null, lease_token: string}|null */
    private function claim(int $storedFileId, bool $abandonedInlineOnly = false): ?array
    {
        return DB::transaction(function () use ($abandonedInlineOnly, $storedFileId): ?array {
            $current = StoredFile::query()->whereKey($storedFileId)->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }

            if ($abandonedInlineOnly
                && (! $current->is_inline
                    || $current->attachable_type !== null
                    || $current->attachable_id !== null
                    || $current->created_at?->isAfter(now()->subHours(24)))) {
                return null;
            }

            $retrying = $current->preview_status === 'deleting';

            if ($retrying && $current->updated_at?->isAfter(now()->subSeconds(self::LEASE_SECONDS))) {
                return null;
            }

            $deletion = [
                'disk' => $current->disk,
                'path' => $current->path,
                'original_name' => $current->original_name,
                'preview_path' => $retrying ? null : $current->preview_path,
                'preview_status' => $retrying ? null : $current->preview_status,
            ];

            $current->update([
                'preview_path' => null,
                'preview_status' => 'deleting',
            ]);

            return [
                ...$deletion,
                'lease_token' => (string) $current->getRawOriginal('updated_at'),
            ];
        });
    }

    /** @param array{path: string, lease_token: string} $deletion */
    private function finalize(int $storedFileId, array $deletion): bool
    {
        return DB::transaction(function () use ($storedFileId, $deletion): bool {
            $current = StoredFile::query()->whereKey($storedFileId)->lockForUpdate()->first();

            if ($current === null
                || $current->path !== $deletion['path']
                || $current->preview_status !== 'deleting'
                || (string) $current->getRawOriginal('updated_at') !== $deletion['lease_token']) {
                return false;
            }

            InlineAttachments::removeFromBody($current);
            InlineAttachments::detachMany((new Comment)->getMorphClass(), $current->comments()->pluck('id'));
            $current->comments()->delete();
            Activity::query()
                ->where('project_id', $current->project_id)
                ->where('subject_type', $current->getMorphClass())
                ->where('subject_id', $current->id)
                ->get()
                ->each(fn (Activity $activity) => $activity->update([
                    'metadata' => [...$activity->metadata, 'subjectDeleted' => true],
                ]));
            $current->delete();

            return true;
        });
    }

    /** @param array{disk: string, path: string, original_name: string, preview_path: string|null, preview_status: string|null, lease_token: string} $deletion */
    private function restoreAfterFailure(int $storedFileId, array $deletion): void
    {
        DB::transaction(function () use ($storedFileId, $deletion): void {
            $current = StoredFile::query()
                ->whereKey($storedFileId)
                ->where('path', $deletion['path'])
                ->where('preview_status', 'deleting')
                ->where('updated_at', $deletion['lease_token'])
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return;
            }

            $needsRegeneration = StoredFilePreviewGenerator::supports($deletion['original_name'])
                && $deletion['preview_status'] !== 'ready';

            $current->update([
                'preview_path' => $deletion['preview_path'],
                'preview_status' => $needsRegeneration ? 'pending' : $deletion['preview_status'],
            ]);

            if ($needsRegeneration) {
                $this->previews->dispatchAfterCommit($storedFileId, $deletion['path']);
            }
        });
    }
}
