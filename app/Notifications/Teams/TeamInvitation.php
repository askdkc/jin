<?php

namespace App\Notifications\Teams;

use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitationModel $invitation)
    {
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
            ? ['broadcast', 'mail']
            : ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->invitation->team;
        $inviter = $this->invitation->inviter;
        $hasAccount = User::query()->where('email', $this->invitation->email)->exists();

        return (new MailMessage)
            ->subject(__("You've been invited to join :teamName", ['teamName' => $team->name]))
            ->line(__(':inviterName has invited you to join the :teamName team.', [
                'inviterName' => $inviter->name,
                'teamName' => $team->name,
            ]))
            ->line($hasAccount
                ? __('Log in and visit your dashboard to accept or decline this invitation.')
                : __('Create an account to accept or decline this invitation.'))
            ->action(
                $hasAccount ? __('Log in') : __('Create account'),
                route($hasAccount ? 'login' : 'register', ['invitation' => $this->invitation->code]),
            );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'team_invitation',
            'invitationId' => $this->invitation->id,
            'invitationCode' => $this->invitation->code,
            'teamId' => $this->invitation->team_id,
            'teamName' => $this->invitation->team->name,
            'inviterName' => $this->invitation->inviter->name,
            'role' => $this->invitation->role->value,
        ];
    }

    public function broadcastType(): string
    {
        return 'team_invitation';
    }
}
