<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Models\StoredFile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new event instance.
     */
    public function __construct(public ChatMessage $message) {}

    /**
     * Get the private project channel for the event.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('projects.'.$this->message->project_id.'.chat'),
        ];
    }

    /**
     * Get the client-side event name.
     */
    public function broadcastAs(): string
    {
        return 'chat.message.created';
    }

    /**
     * Get the broadcast payload.
     *
     * @return array{message: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing(['user:id,name,avatar_path', 'attachments.uploader:id,name']);

        return [
            'message' => [
                'id' => $this->message->id,
                'channelId' => $this->message->chat_channel_id,
                'parentId' => $this->message->parent_id,
                'body' => $this->message->body,
                'createdAt' => $this->message->created_at?->toISOString(),
                'replyCount' => 0,
                'reactions' => [],
                'user' => $this->message->user ? [
                    'id' => $this->message->user->id,
                    'name' => $this->message->user->name,
                    'avatar' => $this->message->user->avatar,
                ] : null,
                'attachments' => $this->message->attachments->map(fn (StoredFile $file): array => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'mimeType' => $file->mime_type,
                    'size' => $file->size,
                    'uploaderName' => $file->uploader?->name,
                    'createdAt' => $file->created_at?->toISOString(),
                ])->all(),
            ],
        ];
    }
}
