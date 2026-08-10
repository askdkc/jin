<?php

namespace App\Actions\Chat;

use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateChatMessage
{
    /** @param list<int> $attachmentIds */
    public function handle(
        Project $project,
        ChatChannel $channel,
        User $actor,
        string $body,
        ?ChatMessage $parent,
        array $attachmentIds,
    ): ChatMessage {
        $attachmentIds = array_values(array_unique($attachmentIds));

        return DB::transaction(function () use ($project, $channel, $actor, $body, $parent, $attachmentIds): ChatMessage {
            $message = $project->chatMessages()->create([
                'chat_channel_id' => $channel->id,
                'user_id' => $actor->id,
                'parent_id' => $parent?->id,
                'body' => $body,
            ]);

            if ($attachmentIds === []) {
                return $message;
            }

            $files = StoredFile::query()
                ->whereIn('id', $attachmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($files->count() !== count($attachmentIds)
                || $files->contains(fn (StoredFile $file): bool => $file->project_id !== $project->id
                    || $file->uploaded_by !== $actor->id
                    || ! $file->is_inline
                    || $file->attachable_type !== null
                    || $file->attachable_id !== null
                    || $file->preview_status === 'deleting')) {
                $this->throwUnavailableAttachments();
            }

            $claimed = StoredFile::query()
                ->whereIn('id', $attachmentIds)
                ->where('project_id', $project->id)
                ->where('uploaded_by', $actor->id)
                ->where('is_inline', true)
                ->whereNull('attachable_type')
                ->whereNull('attachable_id')
                ->update([
                    'attachable_type' => $message->getMorphClass(),
                    'attachable_id' => $message->id,
                ]);

            if ($claimed !== count($attachmentIds)) {
                $this->throwUnavailableAttachments();
            }

            return $message;
        }, attempts: 5);
    }

    private function throwUnavailableAttachments(): never
    {
        throw ValidationException::withMessages([
            'attachment_ids' => [__('One or more inline attachments are no longer available. Remove them and try again.')],
        ]);
    }
}
