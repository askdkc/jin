<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

class MentionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public const Message = 'mention_message';

    public const Todo = 'mention_todo';

    public const File = 'mention_file';

    public const Chat = 'mention_chat';

    public function __construct(
        public string $type,
        public User $actor,
        public Project $project,
        public string $title,
        public ?int $targetId = null,
        public ?int $chatChannelId = null,
        public ?string $chatMessageExcerpt = null,
    ) {
        $this->afterCommit();
    }

    /** @param  Collection<int, User>  $recipients */
    public static function sendToUsers(
        Collection $recipients,
        User $actor,
        Project $project,
        string $type,
        string $title,
        ?int $targetId = null,
        ?int $chatChannelId = null,
        ?string $chatMessageExcerpt = null,
    ): void {
        $recipients = $recipients->reject(fn (User $user): bool => $user->id === $actor->id);

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, new self(
            $type,
            $actor,
            $project,
            $title,
            $targetId,
            $chatChannelId,
            $chatMessageExcerpt,
        ));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? $notifiable->notificationChannels('mention')
            : ['database', 'mail'];
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
            self::Chat => route('projects.chat.show', [
                'current_team' => $this->project->team,
                'project' => $this->project,
                'channel' => $this->chatChannelId,
            ]),
            self::Todo => $this->todoUrl(),
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
            'messageTitle' => in_array($this->type, [self::Message, self::Chat], true) ? $this->title : null,
            'todoTitle' => in_array($this->type, [self::Todo, self::File], true) ? $this->title : null,
            'messageBoardPostId' => $this->type === self::Message ? $this->targetId : null,
            'storedFileId' => $this->type === self::File ? $this->targetId : null,
            'chatMessageId' => $this->type === self::Chat ? $this->targetId : null,
            'chatChannelId' => $this->type === self::Chat ? $this->chatChannelId : null,
            'chatChannelName' => $this->type === self::Chat ? Str::after($this->title, '#') : null,
            'chatMessageExcerpt' => $this->type === self::Chat ? $this->chatMessageExcerpt : null,
            'todoId' => $this->type === self::Todo ? $this->targetId : null,
            'actorName' => $this->actor->name,
        ];
    }

    public function broadcastType(): string
    {
        return $this->type;
    }

    private function todoUrl(): string
    {
        $todo = Todo::query()
            ->select(['id', 'todo_list_id'])
            ->whereHas('todoList', fn (Builder $query) => $query->where('project_id', $this->project->id))
            ->find($this->targetId);

        return $todo
            ? route('projects.todos.show', [
                $this->project->team,
                $this->project,
                $todo->todo_list_id,
                $todo,
            ])
            : route('projects.todos.index', [$this->project->team, $this->project]);
    }
}
