<?php

namespace App\Models;

use Database\Factories\DirectMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $sender_id
 * @property int $recipient_id
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read User $sender
 * @property-read User $recipient
 */
#[Fillable(['team_id', 'sender_id', 'recipient_id', 'body', 'read_at'])]
class DirectMessage extends Model
{
    /** @use HasFactory<DirectMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
