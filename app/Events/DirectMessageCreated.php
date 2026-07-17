<?php

namespace App\Events;

use App\Models\DirectMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DirectMessageCreated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public DirectMessage $message) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->message->recipient_id.'.pings'),
            new PrivateChannel('users.'.$this->message->sender_id.'.pings'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ping.created';
    }

    /** @return array{message: array<string, mixed>} */
    public function broadcastWith(): array
    {
        $this->message->loadMissing(['sender:id,name', 'recipient:id,name']);

        return [
            'message' => [
                'id' => $this->message->id,
                'body' => $this->message->body,
                'read' => $this->message->read_at !== null,
                'createdAt' => $this->message->created_at?->toISOString(),
                'sender' => [
                    'id' => $this->message->sender->id,
                    'name' => $this->message->sender->name,
                ],
                'recipient' => [
                    'id' => $this->message->recipient->id,
                    'name' => $this->message->recipient->name,
                ],
            ],
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
