<?php

namespace App\Support;

final class InlineAttachmentPolicy
{
    /** @var list<string> */
    private const SAFE_RASTER_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    public static function isInline(?string $mimeType): bool
    {
        return in_array($mimeType, self::SAFE_RASTER_MIME_TYPES, true);
    }
}
