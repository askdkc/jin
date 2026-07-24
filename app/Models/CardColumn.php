<?php

namespace App\Models;

use Database\Factories\CardColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $card_table_id
 * @property string $name
 * @property int $position
 * @property bool $is_collapsed
 * @property bool $is_triage
 * @property bool $is_not_now
 * @property bool $is_done
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CardTable $cardTable
 * @property-read Collection<int, Card> $cards
 * @property-read Collection<int, Comment> $comments
 */
#[Fillable(['card_table_id', 'name', 'position', 'is_collapsed', 'is_triage', 'is_not_now', 'is_done'])]
class CardColumn extends Model
{
    /** @use HasFactory<CardColumnFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_collapsed' => false,
        'is_triage' => false,
        'is_not_now' => false,
        'is_done' => false,
    ];

    /** @return BelongsTo<CardTable, $this> */
    public function cardTable(): BelongsTo
    {
        return $this->belongsTo(CardTable::class);
    }

    /** @return HasMany<Card, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_collapsed' => 'boolean',
            'is_triage' => 'boolean',
            'is_not_now' => 'boolean',
            'is_done' => 'boolean',
        ];
    }
}
