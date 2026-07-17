<?php

namespace App\Models;

use App\Casts\SanitizedRichText;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $card_column_id
 * @property int|null $created_by
 * @property int|null $assignee_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $due_on
 * @property int $position
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CardColumn $cardColumn
 * @property-read User|null $creator
 * @property-read User|null $assignee
 */
#[Fillable(['card_column_id', 'created_by', 'assignee_id', 'title', 'description', 'due_on', 'position', 'version'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    /** @return BelongsTo<CardColumn, $this> */
    public function cardColumn(): BelongsTo
    {
        return $this->belongsTo(CardColumn::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'description' => SanitizedRichText::class,
            'due_on' => 'date',
        ];
    }
}
