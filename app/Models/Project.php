<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property int|null $project_folder_id
 * @property int|null $lead_id
 * @property string $name
 * @property string|null $description
 * @property string|null $phase
 * @property string $status
 * @property array<int, string>|null $enabled_tools
 * @property bool $allows_all_clients
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read User|null $lead
 * @property-read ProjectFolder|null $folder
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, User> $favoritedBy
 * @property-read Collection<int, ChatMessage> $chatMessages
 * @property-read Collection<int, MessageBoardPost> $messageBoardPosts
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, TodoList> $todoLists
 * @property-read Collection<int, ScheduleEvent> $scheduleEvents
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, StoredFile> $storedFiles
 * @property-read Collection<int, Activity> $activities
 * @property-read Collection<int, CardTable> $cardTables
 * @property-read Collection<int, CheckIn> $checkIns
 * @property-read Collection<int, MaterialFolder> $materialFolders
 * @property-read Collection<int, ExternalLink> $externalLinks
 * @property-read Collection<int, TimeEntry> $timeEntries
 */
#[Fillable(['team_id', 'created_by', 'project_folder_id', 'lead_id', 'name', 'description', 'phase', 'status', 'enabled_tools', 'allows_all_clients', 'starts_on', 'ends_on', 'archived_at'])]
class Project extends Model
{
    /** @var list<string> */
    public const AVAILABLE_TOOLS = [
        'messages',
        'todos',
        'materials',
        'schedule',
        'chat',
        'card_tables',
        'check_ins',
        'timesheets',
    ];

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the team that owns the project.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /** @return BelongsTo<ProjectFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ProjectFolder::class, 'project_folder_id');
    }

    /**
     * Get the project participants.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_favorites')->withTimestamps();
    }

    /**
     * Limit projects to those visible to the user in the given team.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, User $user, Team $team): Builder
    {
        $role = $user->teamRole($team);

        $query->where('team_id', $team->id);

        if ($role === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($role->isAtLeast(TeamRole::Admin)) {
            return $query;
        }

        return $query->where(function (Builder $accessQuery) use ($role, $user): void {
            $accessQuery->whereHas(
                'members',
                fn (Builder $memberQuery) => $memberQuery->whereKey($user->id),
            );

            if ($role === TeamRole::Client) {
                $accessQuery->orWhere('allows_all_clients', true);
            }
        });
    }

    /**
     * Determine whether the user can access this project.
     */
    public function isVisibleTo(User $user): bool
    {
        return self::query()
            ->visibleTo($user, $this->team)
            ->whereKey($this->id)
            ->exists();
    }

    /**
     * Get the chat messages for the project.
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** @return HasMany<ChatChannel, $this> */
    public function chatChannels(): HasMany
    {
        return $this->hasMany(ChatChannel::class);
    }

    /** @return HasMany<MessageBoardPost, $this> */
    public function messageBoardPosts(): HasMany
    {
        return $this->hasMany(MessageBoardPost::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<TodoList, $this> */
    public function todoLists(): HasMany
    {
        return $this->hasMany(TodoList::class);
    }

    /** @return HasMany<ScheduleEvent, $this> */
    public function scheduleEvents(): HasMany
    {
        return $this->hasMany(ScheduleEvent::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<StoredFile, $this> */
    public function storedFiles(): HasMany
    {
        return $this->hasMany(StoredFile::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<CardTable, $this> */
    public function cardTables(): HasMany
    {
        return $this->hasMany(CardTable::class);
    }

    /** @return HasMany<CheckIn, $this> */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /** @return HasMany<MaterialFolder, $this> */
    public function materialFolders(): HasMany
    {
        return $this->hasMany(MaterialFolder::class);
    }

    /** @return HasMany<ExternalLink, $this> */
    public function externalLinks(): HasMany
    {
        return $this->hasMany(ExternalLink::class);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return list<string> */
    public function enabledTools(): array
    {
        if ($this->enabled_tools === null) {
            return self::AVAILABLE_TOOLS;
        }

        return array_values(array_intersect(self::AVAILABLE_TOOLS, $this->enabled_tools));
    }

    public function usesTool(string $tool): bool
    {
        return in_array($tool, $this->enabledTools(), true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'archived_at' => 'datetime',
            'allows_all_clients' => 'boolean',
            'enabled_tools' => 'array',
        ];
    }
}
