<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\CheckInPromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
