<?php

namespace App\Jobs;

use App\Models\PersonalNoteAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeletePersonalNoteAttachmentFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [15, 60, 300, 900, 1800, 3600];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $attachmentId,
        public string $disk,
        public string $path,
    ) {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    public function handle(): void
    {
        $attachment = PersonalNoteAttachment::withTrashed()->find($this->attachmentId);

        if ($attachment instanceof PersonalNoteAttachment && ! $attachment->trashed()) {
            return;
        }

        $disk = Storage::disk($this->disk);

        if ($disk->exists($this->path) && ! $disk->delete($this->path)) {
            if (Storage::disk($this->disk)->exists($this->path)) {
                throw new RuntimeException('Personal note attachment file deletion failed.');
            }
        }

        $attachment?->forceDelete();
    }
}
