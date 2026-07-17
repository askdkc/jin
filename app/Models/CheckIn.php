<?php

namespace App\Models;

use App\Enums\CheckInFrequency;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by
 * @property string $question
 * @property CheckInFrequency $frequency
 * @property string $timezone
 * @property Carbon $next_run_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $creator
 * @property-read Collection<int, User> $targets
 * @property-read Collection<int, CheckInPrompt> $prompts
 */
#[Fillable(['project_id', 'created_by', 'question', 'frequency', 'timezone', 'next_run_at', 'paused_at'])]
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
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

    /** @return BelongsToMany<User, $this> */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'check_in_user')->withTimestamps();
    }

    /** @return HasMany<CheckInPrompt, $this> */
    public function prompts(): HasMany
    {
        return $this->hasMany(CheckInPrompt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'frequency' => CheckInFrequency::class,
            'next_run_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }
}
