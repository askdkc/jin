<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTodoListCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $actor,
        public Project $project,
        public TodoList $todoList,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? $notifiable->notificationChannels()
            : ['database', 'mail'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            'mail' => 'notifications',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':messageTitle', ['messageTitle' => $this->todoList->title]))
            ->line(__(':actorName commented on :messageTitle.', [
                'actorName' => $this->actor->name,
                'messageTitle' => $this->todoList->title,
            ]))
            ->action(__('View'), route('projects.todo-lists.show', [$this->project->team, $this->project, $this->todoList]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'todo_list_comment',
            'teamId' => $this->project->team_id,
            'projectId' => $this->project->id,
            'projectName' => $this->project->name,
            'todoListId' => $this->todoList->id,
            'todoTitle' => $this->todoList->title,
            'actorName' => $this->actor->name,
        ];
    }
}
