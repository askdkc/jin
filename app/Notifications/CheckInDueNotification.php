<?php

namespace App\Notifications;

use App\Models\CheckInPrompt;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CheckInDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public CheckInPrompt $prompt)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
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
        $checkIn = $this->prompt->checkIn;
        $project = $checkIn->project;

        return (new MailMessage)
            ->subject($checkIn->question)
            ->line(__('A new check-in question is ready to answer: :question', ['question' => $checkIn->question]))
            ->action(__('Answer check-in'), route('projects.check-ins.index', [$project->team, $project]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $checkIn = $this->prompt->checkIn;
        $project = $checkIn->project;

        return [
            'type' => 'check_in_due',
            'teamId' => $project->team_id,
            'projectId' => $project->id,
            'projectName' => $project->name,
            'checkInId' => $checkIn->id,
            'checkInQuestion' => $checkIn->question,
            'promptId' => $this->prompt->id,
        ];
    }
}
