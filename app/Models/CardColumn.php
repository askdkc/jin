<?php

namespace App\Models;

use Database\Factories\CardColumnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $card_table_id
 * @property string $name
 * @property int $position
 * @property bool $is_collapsed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CardTable $cardTable
 * @property-read Collection<int, Card> $cards
 */
#[Fillable(['card_table_id', 'name', 'position', 'is_collapsed'])]
class CardColumn extends Model
{
    /** @use HasFactory<CardColumnFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_collapsed' => 'boolean'];
    }
}
