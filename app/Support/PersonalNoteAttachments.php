<?php

namespace App\Support;

use App\Models\PersonalNote;
use App\Models\PersonalNoteAttachment;

class PersonalNoteAttachments
{
    /**
     * Claim eligible uploads referenced by a note and remove stale claims.
     */
    public function syncFromBody(PersonalNote $note, int $uploaderId, ?string $body): void
    {
        $attachmentIds = $this->attachmentIdsFromBody($body);

        PersonalNoteAttachment::query()
            ->where('personal_note_id', $note->id)
            ->when(
                $attachmentIds !== [],
                fn ($query) => $query->whereNotIn('id', $attachmentIds),
            )
            ->each(fn (PersonalNoteAttachment $attachment) => $attachment->delete());

        if ($attachmentIds === []) {
            return;
        }

        PersonalNoteAttachment::query()
            ->whereIn('id', $attachmentIds)
            ->where('team_id', $note->team_id)
            ->where('uploaded_by', $uploaderId)
            ->where(function ($query) use ($note): void {
                $query->whereNull('personal_note_id')
                    ->orWhere('personal_note_id', $note->id);
            })
            ->update(['personal_note_id' => $note->id]);
    }

    /**
     * Delete all attachments currently claimed by a note.
     */
    public function deleteFor(PersonalNote $note): void
    {
        PersonalNoteAttachment::query()
            ->where('personal_note_id', $note->id)
            ->each(fn (PersonalNoteAttachment $attachment) => $attachment->delete());
    }

    /** @return list<int> */
    private function attachmentIdsFromBody(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        preg_match_all(
            '~/(?:[^/\s"\'<>]+/)*notes/attachments/(\d+)/preview(?:[/?#\s"\'<>]|$)~',
            $body,
            $matches,
        );

        return array_values(array_unique(array_map(
            static fn (string $id): int => (int) $id,
            $matches[1],
        )));
    }
}
