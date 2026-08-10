<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageDeleted implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new event instance.
     */
    /** @param list<int> $attachmentIds */
    public function __construct(
        public int $projectId,
        public int $messageId,
        public ?int $parentId,
        public array $attachmentIds,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('projects.'.$this->projectId.'.chat'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    /** @return array{messageId: int, parentId: int|null, attachmentIds: list<int>} */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->messageId,
            'parentId' => $this->parentId,
            'attachmentIds' => $this->attachmentIds,
        ];
    }
}
