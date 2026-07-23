<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamAuditEvent;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamAuditLog;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $email = strtolower($request->validated('email'));
        $invitation = DB::transaction(function () use ($email, $request, $team): TeamInvitation {
            $wasInvitedBefore = $team->invitations()
                ->where('email', $email)
                ->exists() || $team->auditLogs()
                ->where('email', $email)
                ->whereIn('event', [
                    TeamAuditEvent::InvitationSent,
                    TeamAuditEvent::InvitationReissued,
                ])
                ->exists();

            $invitation = $team->invitations()->create([
                'email' => $email,
                'role' => TeamRole::from($request->validated('role')),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(3),
            ]);

            TeamAuditLog::record(
                team: $team,
                event: $wasInvitedBefore
                    ? TeamAuditEvent::InvitationReissued
                    : TeamAuditEvent::InvitationSent,
                actor: $request->user(),
                email: $invitation->email,
                metadata: [
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at?->toISOString(),
                ],
            );

            return $invitation;
        });

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back(fallback: route('teams.edit', ['team' => $team->slug]));
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Request $request, Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        DB::transaction(function () use ($request, $team, $invitation): void {
            TeamAuditLog::record(
                team: $team,
                event: TeamAuditEvent::InvitationRevoked,
                actor: $request->user(),
                email: $invitation->email,
            );

            $invitation->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        if (! $invitation->role->isAssignable()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation role is no longer available.'),
            ]);
        }

        $user = $request->user();

        DB::transaction(function () use ($user, $invitation) {
            $team = $invitation->team;

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->update(['accepted_at' => now()]);

            TeamAuditLog::record(
                team: $team,
                event: TeamAuditEvent::InvitationAccepted,
                actor: $user,
                subjectUser: $user,
                email: $invitation->email,
            );

            $user->switchTeam($team);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        DB::transaction(function () use ($request, $invitation): void {
            TeamAuditLog::record(
                team: $invitation->team,
                event: TeamAuditEvent::InvitationDeclined,
                actor: $request->user(),
                subjectUser: $request->user(),
                email: $invitation->email,
            );

            $invitation->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
