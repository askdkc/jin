<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProjectActivityRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $projectId,
        public int $activityId,
    ) {}

    /**
     * Get the private project channel for the event.
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
        return 'project.activity.recorded';
    }

    /** @return array{projectId: int, activityId: int} */
    public function broadcastWith(): array
    {
        return [
            'projectId' => $this->projectId,
            'activityId' => $this->activityId,
        ];
    }
}
