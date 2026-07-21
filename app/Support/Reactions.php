<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Reactions
{
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
     * @param  Collection<int, User>  $reactors
     * @return list<array{reaction: string, count: int, reacted: bool, names: list<string>}>
     */
    public static function grouped(Collection $reactors, int $userId): array
    {
        return $reactors
            ->groupBy(function (User $user): string {
                /** @var Pivot|null $pivot */
                $pivot = $user->getRelation('pivot');

                return (string) $pivot?->getAttribute('reaction');
            })
            ->map(fn ($users, string $reaction): array => [
                'reaction' => $reaction,
                'count' => $users->count(),
                'reacted' => $users->contains('id', $userId),
                'names' => $users->pluck('name')->all(),
            ])
            ->values()
            ->all();
    }
}
