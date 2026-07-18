<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class MentionNotification extends Notification
{
    use Queueable;

    public const Message = 'mention_message';

    public const Todo = 'mention_todo';

    public const File = 'mention_file';

    public function __construct(
        public string $type,
        public User $actor,
        public Project $project,
        public string $title,
        public ?int $targetId = null,
    ) {}

    /** @param  Collection<int, User>  $recipients */
    public static function sendToUsers(
        Collection $recipients,
        User $actor,
        Project $project,
        string $type,
        string $title,
        ?int $targetId = null,
    ): void {
        $recipients = $recipients->reject(fn (User $user): bool => $user->id === $actor->id);

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, new self($type, $actor, $project, $title, $targetId));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = match ($this->type) {
            self::Message => $this->targetId !== null
                ? route('projects.messages.show', [$this->project->team, $this->project, $this->targetId])
                : route('projects.messages.index', [$this->project->team, $this->project]),
            self::File => $this->targetId !== null
                ? route('projects.files.show', [$this->project->team, $this->project, $this->targetId])
                : route('projects.materials.index', [$this->project->team, $this->project]),
            default => route('projects.todos.index', [$this->project->team, $this->project]),
        };

        return (new MailMessage)
            ->subject(__(':actorName mentioned you', ['actorName' => $this->actor->name]))
            ->line(__(':actorName mentioned you in :title.', [
                'actorName' => $this->actor->name,
                'title' => $this->title,
            ]))
            ->action(__('View'), $url);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'teamId' => $this->project->team_id,
            'projectId' => $this->project->id,
            'projectName' => $this->project->name,
            'messageTitle' => $this->type === self::Message ? $this->title : null,
            // File mentions reuse the todoTitle display slot so they render without new mappers.
            'todoTitle' => in_array($this->type, [self::Todo, self::File], true) ? $this->title : null,
            'messageBoardPostId' => $this->type === self::Message ? $this->targetId : null,
            'actorName' => $this->actor->name,
        ];
    }
}
