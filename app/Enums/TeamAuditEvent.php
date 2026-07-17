<?php

namespace App\Enums;

enum TeamAuditEvent: string
{
    case InvitationSent = 'invitation.sent';
    case InvitationReissued = 'invitation.reissued';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationExpired = 'invitation.expired';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationDeclined = 'invitation.declined';
    case ProjectMemberRemoved = 'project.member_removed';

    public function label(): string
    {
        return match ($this) {
            self::InvitationSent => __('Invitation sent'),
            self::InvitationReissued => __('Invitation reissued'),
            self::InvitationRevoked => __('Invitation revoked'),
            self::InvitationExpired => __('Invitation expired'),
            self::InvitationAccepted => __('Invitation accepted'),
            self::InvitationDeclined => __('Invitation declined'),
            self::ProjectMemberRemoved => __('Project access removed'),
        };
    }
}
