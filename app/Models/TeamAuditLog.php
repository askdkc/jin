<?php

namespace App\Models;

use App\Enums\TeamAuditEvent;
use Database\Factories\TeamAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $actor_id
 * @property int|null $subject_user_id
 * @property int|null $project_id
 * @property TeamAuditEvent $event
 * @property string|null $email
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read User|null $actor
 * @property-read User|null $subjectUser
 * @property-read Project|null $project
 */
#[Fillable(['team_id', 'actor_id', 'subject_user_id', 'project_id', 'event', 'email', 'metadata'])]
class TeamAuditLog extends Model
{
    /** @use HasFactory<TeamAuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        Team $team,
        TeamAuditEvent $event,
        ?User $actor = null,
        ?User $subjectUser = null,
        ?Project $project = null,
        ?string $email = null,
        array $metadata = [],
    ): self {
        return $team->auditLogs()->create([
            'actor_id' => $actor?->id,
            'subject_user_id' => $subjectUser?->id,
            'project_id' => $project?->id,
            'event' => $event,
            'email' => $email,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event' => TeamAuditEvent::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
