<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class TodoActivityNotification extends Notification
{
    use Queueable;

    public const Assigned = 'todo_assigned';

    public const Commented = 'todo_comment';

    public const Completed = 'todo_completed';

    public const Due = 'todo_due';

    public function __construct(
        public string $type,
        public ?User $actor,
        public Project $project,
        public Todo $todo,
    ) {}

    /** @param array<int, int|null> $recipientIds */
    public static function sendToUsers(
        array $recipientIds,
        ?User $actor,
        Project $project,
        Todo $todo,
        string $type,
    ): void {
        $ids = collect($recipientIds)
            ->filter(fn (?int $id): bool => $id !== null && $id !== $actor?->id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $recipients = User::query()->whereKey($ids)->get();
        NotificationFacade::send($recipients, new self($type, $actor, $project, $todo));
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $line = match ($this->type) {
            self::Assigned => __(':actorName assigned you to :todoTitle.', [
                'actorName' => $this->actor->name ?? __('Someone'),
                'todoTitle' => $this->todo->title,
            ]),
            self::Commented => __(':actorName commented on :todoTitle.', [
                'actorName' => $this->actor->name ?? __('Someone'),
                'todoTitle' => $this->todo->title,
            ]),
            self::Completed => __(':actorName completed :todoTitle.', [
                'actorName' => $this->actor->name ?? __('Someone'),
                'todoTitle' => $this->todo->title,
            ]),
            default => __(':todoTitle is due soon.', ['todoTitle' => $this->todo->title]),
        };

        return (new MailMessage)
            ->subject($this->todo->title)
            ->line($line)
            ->action(__('View to-do'), route('projects.todos.index', [$this->project->team, $this->project]));
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
            'todoId' => $this->todo->id,
            'todoTitle' => $this->todo->title,
            'actorName' => $this->actor?->name,
        ];
    }
}
