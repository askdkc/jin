<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $todo_list_id
 * @property int|null $chat_channel_id
 * @property int|null $parent_id
 * @property int|null $assignee_id
 * @property array<int, int>|null $completion_notify_ids
 * @property int|null $created_by
 * @property string $title
 * @property string|null $details
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TodoList $todoList
 * @property-read ChatChannel|null $chatChannel
 * @property-read Todo|null $parent
 * @property-read Collection<int, Todo> $subtasks
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read User|null $completer
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, StoredFile> $attachments
 * @property-read Collection<int, User> $reactors
 */
#[Fillable(['todo_list_id', 'chat_channel_id', 'parent_id', 'assignee_id', 'completion_notify_ids', 'created_by', 'title', 'details', 'due_on', 'completed_at', 'completed_by', 'position'])]
class Todo extends Model
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory;

    /** @return BelongsTo<TodoList, $this> */
    public function todoList(): BelongsTo
    {
        return $this->belongsTo(TodoList::class);
    }

    /** @return BelongsTo<ChatChannel, $this> */
    public function chatChannel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class);
    }

    /** @return BelongsTo<Todo, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Todo, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return MorphMany<StoredFile, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(StoredFile::class, 'attachable');
    }

    /** @return BelongsToMany<User, $this> */
    public function reactors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'todo_reactions')
            ->withPivot('reaction')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'details' => SanitizedRichText::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'completion_notify_ids' => 'array',
        ];
    }
}
