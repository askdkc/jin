<?php

namespace App\Support;

final class StoredFileContentSanitizer
{
    private const EMBEDDED_IMAGE_PATTERN = '/!\[([^\]\r\n]*)\]\(\s*data:image\/[^)]*(?:\)|\z)/iu';

    public function sanitize(string $content): string
    {
        return preg_replace_callback(
            self::EMBEDDED_IMAGE_PATTERN,
            static function (array $matches): string {
                $alternativeText = trim($matches[1]);

                return $alternativeText === ''
                    ? ' '
                    : ' '.$alternativeText.' ';
            },
            $content,
        ) ?? $content;
    }
}
