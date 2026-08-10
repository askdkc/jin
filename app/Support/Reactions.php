<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Reactions
{
    /**
     * @template TDeclaringModel of Model
     *
     * @param  BelongsToMany<User, TDeclaringModel>  $reactors
     */
    public static function toggle(BelongsToMany $reactors, int $userId, string $reaction): void
    {
        $current = $reactors->whereKey($userId)->first()?->pivot?->getAttribute('reaction');

        if ($current === $reaction) {
            $reactors->detach($userId);

            return;
        }

        $reactors->syncWithoutDetaching([$userId => ['reaction' => $reaction]]);
    }

    /**
     * Toggle one reaction without replacing the user's other reactions.
     *
     * @template TDeclaringModel of Model
     *
     * @param  BelongsToMany<User, TDeclaringModel>  $reactors
     */
    public static function toggleMultiple(BelongsToMany $reactors, int $userId, string $reaction): void
    {
        $pivot = $reactors->newPivotQuery()
            ->where($reactors->getRelatedPivotKeyName(), $userId)
            ->where('reaction', $reaction);

        if ($pivot->exists()) {
            $pivot->delete();

            return;
        }

        $reactors->attach($userId, ['reaction' => $reaction]);
    }

    /**
     * @param  Collection<int, User>  $reactors
     * @return list<array{reaction: string, count: int, reacted: bool, names: list<string>}>
     */
    public static function grouped(Collection $reactors, int $userId): array
    {
        $grouped = [];

        foreach ($reactors as $user) {
            $pivot = $user->getRelation('pivot');
            $reaction = $pivot instanceof Pivot
                ? (string) $pivot->getAttribute('reaction')
                : '';

            if ($reaction === '') {
                continue;
            }

            $grouped[$reaction] ??= [
                'reaction' => $reaction,
                'count' => 0,
                'reacted' => false,
                'names' => [],
            ];
            $grouped[$reaction]['count']++;
            $grouped[$reaction]['reacted'] = $grouped[$reaction]['reacted'] || $user->id === $userId;
            $grouped[$reaction]['names'][] = $user->name;
        }

        return array_values($grouped);
    }
}
