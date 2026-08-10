<?php

namespace App\Notifications;

use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

class NewChatMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public User $actor,
        public Project $project,
        public ChatChannel $channel,
        public ChatMessage $message,
    ) {
        $this->afterCommit();
    }

    /** @param  Collection<int, User>  $recipients */
    public static function sendToUsers(
        Collection $recipients,
        User $actor,
        Project $project,
        ChatChannel $channel,
        ChatMessage $message,
    ): void {
        $recipients = $recipients
            ->reject(fn (User $user): bool => $user->id === $actor->id)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, new self(
            $actor,
            $project,
            $channel,
            $message,
        ));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database', 'broadcast'];
        }

        return array_values(array_intersect(
            $notifiable->notificationChannels('activity'),
            ['database', 'broadcast'],
        ));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'chat_message',
            'teamId' => $this->project->team_id,
            'projectId' => $this->project->id,
            'projectName' => $this->project->name,
            'messageTitle' => "#{$this->channel->name}",
            'chatMessageId' => $this->message->id,
            'chatChannelId' => $this->channel->id,
            'chatChannelName' => $this->channel->name,
            'chatMessageExcerpt' => Str::limit(RichText::plainText($this->message->body), 120),
            'actorName' => $this->actor->name,
        ];
    }

    public function broadcastType(): string
    {
        return 'chat_message';
    }
}
