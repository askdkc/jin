<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $todo_id
 * @property int $user_id
 * @property Carbon $reminder_on
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $processing_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $skipped_at
 * @property string|null $skip_reason
 * @property string|null $last_error
 * @property-read Todo $todo
 * @property-read User $user
 */
#[Fillable(['todo_id', 'user_id', 'reminder_on', 'dispatched_at', 'processing_at', 'queued_at', 'skipped_at', 'skip_reason', 'last_error'])]
class TodoReminderDelivery extends Model
{
    /** @return BelongsTo<Todo, $this> */
    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reminder_on' => 'date',
            'dispatched_at' => 'datetime',
            'processing_at' => 'datetime',
            'queued_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }
}
