<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $check_in_prompt_id
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $processing_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $skipped_at
 * @property string|null $skip_reason
 * @property string|null $last_error
 * @property-read CheckInPrompt $prompt
 */
#[Fillable(['check_in_prompt_id', 'dispatched_at', 'processing_at', 'queued_at', 'skipped_at', 'skip_reason', 'last_error'])]
class CheckInNotificationDelivery extends Model
{
    /** @return BelongsTo<CheckInPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(CheckInPrompt::class, 'check_in_prompt_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'processing_at' => 'datetime',
            'queued_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }
}
