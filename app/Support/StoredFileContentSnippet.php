<?php

namespace App\Support;

use Illuminate\Support\Str;

final class StoredFileContentSnippet
{
    private const MAX_LENGTH = 240;

    public function __construct(
        private StoredFileContentSanitizer $sanitizer,
    ) {}

    public function make(string $content, string $search): ?string
    {
        $content = Str::squish($this->sanitizer->sanitize($content));
        $search = Str::squish($search);

        if ($content === '' || $search === '') {
            return null;
        }

        $matchPosition = mb_stripos($content, $search, 0, 'UTF-8');

        if ($matchPosition === false) {
            return null;
        }

        $contentLength = mb_strlen($content, 'UTF-8');

        if ($contentLength <= self::MAX_LENGTH) {
            return $content;
        }

        $searchLength = mb_strlen($search, 'UTF-8');
        $initialContextLength = self::MAX_LENGTH - min($searchLength, self::MAX_LENGTH);
        $start = max(0, $matchPosition - intdiv($initialContextLength, 2));
        $start = min($start, $contentLength - self::MAX_LENGTH);
        $hasPrefix = $start > 0;
        $hasSuffix = $start + self::MAX_LENGTH < $contentLength;
        $excerptLength = self::MAX_LENGTH
            - ($hasPrefix ? 1 : 0)
            - ($hasSuffix ? 1 : 0);
        $leftContextLength = max(0, intdiv($excerptLength - $searchLength, 2));
        $start = max(0, $matchPosition - $leftContextLength);
        $start = min($start, $contentLength - $excerptLength);
        $hasPrefix = $start > 0;
        $hasSuffix = $start + $excerptLength < $contentLength;
        $excerpt = mb_substr($content, $start, $excerptLength, 'UTF-8');

        return ($hasPrefix ? '…' : '')
            .$excerpt
            .($hasSuffix ? '…' : '');
    }
}
