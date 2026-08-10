<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageReactionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new event instance.
     */
    public function __construct(public ChatMessage $message) {}

    /**
     * Get the channels the event should broadcast on.
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
        return 'chat.message.reaction.updated';
    }

    /**
     * Get the broadcast payload.
     *
     * @return array{messageId: int, reactions: list<array{reaction: string, count: int, names: list<string>, userIds: list<int>}>}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('reactors:id,name');
        $grouped = [];

        foreach ($this->message->reactors as $user) {
            $pivot = $user->getRelation('pivot');
            $reaction = $pivot instanceof Pivot
                ? (string) $pivot->getAttribute('reaction')
                : '';

            if ($reaction === '') {
                continue;
            }

            $grouped[$reaction] ??= [
                'reaction' => $reaction,
                'count' => 0,
                'names' => [],
                'userIds' => [],
            ];
            $grouped[$reaction]['count']++;
            $grouped[$reaction]['names'][] = $user->name;
            $grouped[$reaction]['userIds'][] = $user->id;
        }

        return [
            'messageId' => $this->message->id,
            'reactions' => array_values($grouped),
        ];
    }
}
