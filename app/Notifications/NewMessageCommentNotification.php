<?php

namespace App\Notifications;

use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public User $actor,
        public Project $project,
        public MessageBoardPost $messageBoardPost,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? $notifiable->notificationChannels()
            : ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':messageTitle', ['messageTitle' => $this->messageBoardPost->title]))
            ->line(__(':actorName commented on :messageTitle.', [
                'actorName' => $this->actor->name,
                'messageTitle' => $this->messageBoardPost->title,
            ]))
            ->action(__('View message'), route('projects.messages.show', [$this->project->team, $this->project, $this->messageBoardPost]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'message_comment',
            'teamId' => $this->project->team_id,
            'projectId' => $this->project->id,
            'projectName' => $this->project->name,
            'messageBoardPostId' => $this->messageBoardPost->id,
            'messageTitle' => $this->messageBoardPost->title,
            'actorName' => $this->actor->name,
        ];
    }

    public function broadcastType(): string
    {
        return 'message_comment';
    }
}
