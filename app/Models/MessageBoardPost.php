<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\MessageBoardPostFactory;
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
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property Carbon $published_at
 * @property Carbon|null $pinned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $user
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, User> $subscribers
 * @property-read Collection<int, User> $reactors
 * @property-read Collection<int, StoredFile> $attachments
 */
#[Fillable(['project_id', 'user_id', 'title', 'body', 'published_at', 'pinned_at'])]
class MessageBoardPost extends Model
{
    /** @use HasFactory<MessageBoardPostFactory> */
    use HasFactory;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_board_post_reactions')
            ->withPivot('reaction')
            ->withTimestamps();
    }

    /** @return MorphMany<StoredFile, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(StoredFile::class, 'attachable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'body' => SanitizedRichText::class,
            'published_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }
}
