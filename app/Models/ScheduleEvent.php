<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\ScheduleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $details
 * @property string|null $meeting_url
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $all_day
 * @property string|null $recurrence
 * @property string|null $recurrence_group_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, User> $reactors
 */
#[Fillable(['project_id', 'created_by', 'title', 'details', 'meeting_url', 'starts_at', 'ends_at', 'all_day', 'recurrence', 'recurrence_group_id'])]
class ScheduleEvent extends Model
{
    /** @var list<string> */
    public const RECURRENCES = ['daily', 'weekly', 'monthly'];

    /** @use HasFactory<ScheduleEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'schedule_event_reactions')
            ->withPivot('reaction')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'details' => SanitizedRichText::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }
}
