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
        $html = (string) $body;
        preg_match_all(
            '#href\s*=\s*["\']/mentions/(\d+)["\']#i',
            $html,
            $semanticMatches,
        );
        $semanticIds = collect($semanticMatches[1])
            ->map(fn (string $id): int => (int) $id)
            ->unique();
        $legacyHtml = preg_replace(
            '#<a\b[^>]*href\s*=\s*["\']/mentions/\d+["\'][^>]*>.*?</a>#is',
            '',
            $html,
        ) ?? $html;
        $plainText = html_entity_decode(strip_tags($legacyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $candidates->filter(
            fn (User $user): bool => $semanticIds->contains($user->id)
                || preg_match(
                    '/@'.preg_quote($user->name, '/').'(?!\p{L}|\p{N})/u',
                    $plainText,
                ) === 1,
        )->values();
    }
}
