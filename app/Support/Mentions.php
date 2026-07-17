<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class Mentions
{
    /**
     * Find which of the given candidates are @mentioned in the rich text body.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public static function extract(?string $body, Collection $candidates): Collection
    {
        $plainText = html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $candidates->filter(
            fn (User $user): bool => preg_match(
                '/@'.preg_quote($user->name, '/').'(?!\p{L}|\p{N})/u',
                $plainText,
            ) === 1,
        )->values();
    }
}
