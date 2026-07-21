<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\CheckInPromptFactory;
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
 * @property int $check_in_id
 * @property int|null $user_id
 * @property Carbon $scheduled_for
 * @property Carbon $requested_at
 * @property string|null $answer
 * @property Carbon|null $answered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CheckIn $checkIn
 * @property-read User|null $user
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, User> $reactors
 */
#[Fillable(['check_in_id', 'user_id', 'scheduled_for', 'requested_at', 'answer', 'answered_at'])]
class CheckInPrompt extends Model
{
    /** @use HasFactory<CheckInPromptFactory> */
    use HasFactory;

    /** @return BelongsTo<CheckIn, $this> */
    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'check_in_prompt_reactions')
            ->withPivot('reaction')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'answer' => SanitizedRichText::class,
            'scheduled_for' => 'datetime',
            'requested_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }
}
