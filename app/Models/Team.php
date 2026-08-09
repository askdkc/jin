<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property array<string, string>|null $menu_names
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, CalendarSubscription> $calendarSubscriptions
 * @property-read Collection<int, TeamAuditLog> $auditLogs
 * @property-read Collection<int, ProjectFolder> $projectFolders
 * @property-read Collection<int, PersonalNote> $personalNotes
 * @property-read Collection<int, PersonalNoteAttachment> $personalNoteAttachments
 * @property-read Collection<int, BubbleUp> $bubbleUps
 * @property-read Collection<int, DirectMessage> $directMessages
 */
#[Fillable(['name', 'slug', 'is_personal', 'menu_names'])]
class Team extends Model
{
    /** @var array<string, string> */
    public const DEFAULT_MENU_NAMES = [
        'projectHome' => '本陣',
        'todos' => '任務',
        'cardTable' => '作戦盤',
        'checkIns' => '振り返り',
        'calendar' => '行程',
        'lineup' => '布陣',
        'chat' => '会話',
        'reports' => '戦況',
        'timesheets' => '稼働',
        'adminland' => '管理所',
    ];

    /** @var array<string, string> */
    private const DEFAULT_MENU_TRANSLATION_KEYS = [
        'projectHome' => 'Project home',
        'todos' => 'To-do',
        'cardTable' => 'Card table',
        'checkIns' => 'Retrospectives',
        'calendar' => 'Calendar',
        'lineup' => 'Lineup',
        'chat' => 'Real-time chat',
        'reports' => 'Reports',
        'timesheets' => 'Time entries',
        'adminland' => 'Adminland',
    ];

    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });

        static::forceDeleting(function (Team $team): void {
            $team->personalNoteAttachments()
                ->each(fn (PersonalNoteAttachment $attachment) => $attachment->delete());
        });
    }

    /** @return array<string, string> */
    public static function localizedDefaultMenuNames(): array
    {
        $localizedDefaults = [];

        foreach (self::DEFAULT_MENU_TRANSLATION_KEYS as $menu => $translationKey) {
            $localizedDefaults[$menu] = (string) __($translationKey);
        }

        return $localizedDefaults;
    }

    /** @return array<string, string> */
    public function menuNames(): array
    {
        $localizedDefaults = self::localizedDefaultMenuNames();
        $overrides = array_intersect_key($this->menu_names ?? [], $localizedDefaults);

        foreach (self::DEFAULT_MENU_NAMES as $menu => $legacyDefault) {
            if (($overrides[$menu] ?? null) === $legacyDefault) {
                unset($overrides[$menu]);
            }
        }

        return array_replace($localizedDefaults, $overrides);
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all projects for this team.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get calendar subscriptions issued for this team.
     *
     * @return HasMany<CalendarSubscription, $this>
     */
    public function calendarSubscriptions(): HasMany
    {
        return $this->hasMany(CalendarSubscription::class);
    }

    /**
     * Get the team's audit history.
     *
     * @return HasMany<TeamAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(TeamAuditLog::class);
    }

    /** @return HasMany<ProjectFolder, $this> */
    public function projectFolders(): HasMany
    {
        return $this->hasMany(ProjectFolder::class);
    }

    /** @return HasMany<PersonalNote, $this> */
    public function personalNotes(): HasMany
    {
        return $this->hasMany(PersonalNote::class);
    }

    /** @return HasMany<PersonalNoteAttachment, $this> */
    public function personalNoteAttachments(): HasMany
    {
        return $this->hasMany(PersonalNoteAttachment::class);
    }

    /** @return HasMany<BubbleUp, $this> */
    public function bubbleUps(): HasMany
    {
        return $this->hasMany(BubbleUp::class);
    }

    /** @return HasMany<DirectMessage, $this> */
    public function directMessages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'menu_names' => 'array',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
