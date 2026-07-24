<?php

namespace App\Console\Commands;

use App\Jobs\DeletePersonalNoteAttachmentFile;
use App\Models\PersonalNoteAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('personal-note-attachments:prune')]
#[Description('Tombstone abandoned personal-note attachments and retry physical cleanup')]
class PrunePersonalNoteAttachments extends Command
{
    public function handle(): int
    {
        $tombstoned = 0;
        $retried = 0;

        PersonalNoteAttachment::query()
            ->abandoned()
            ->select(['id', 'disk', 'path'])
            ->eachById(function (PersonalNoteAttachment $attachment) use (&$tombstoned): void {
                if ($attachment->delete()) {
                    $tombstoned++;
                }
            }, 200);

        PersonalNoteAttachment::onlyTrashed()
            ->select(['id', 'disk', 'path'])
            ->eachById(function (PersonalNoteAttachment $attachment) use (&$retried): void {
                DeletePersonalNoteAttachmentFile::dispatch(
                    $attachment->id,
                    $attachment->disk,
                    $attachment->path,
                );
                $retried++;
            }, 200);

        $this->components->info("Tombstoned {$tombstoned} abandoned attachment(s).");
        $this->components->info("Queued {$retried} attachment cleanup job(s).");

        return self::SUCCESS;
    }
}
