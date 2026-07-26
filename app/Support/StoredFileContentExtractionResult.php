<?php

namespace App\Support;

use App\Enums\StoredFileContentFailure;
use App\Enums\StoredFileContentStatus;

final readonly class StoredFileContentExtractionResult
{
    public function __construct(
        public StoredFileContentStatus $status,
        public string $extractor,
        public ?string $content,
        public int $contentBytes,
        public bool $isTruncated,
        public ?StoredFileContentFailure $failure,
    ) {}

    public static function ready(string $extractor, ?string $content, bool $isTruncated): self
    {
        return new self(
            status: StoredFileContentStatus::Ready,
            extractor: $extractor,
            content: $content,
            contentBytes: strlen($content ?? ''),
            isTruncated: $isTruncated,
            failure: null,
        );
    }

    public static function protected(string $extractor): self
    {
        return new self(
            status: StoredFileContentStatus::Protected,
            extractor: $extractor,
            content: null,
            contentBytes: 0,
            isTruncated: false,
            failure: StoredFileContentFailure::EncryptedDocument,
        );
    }

    public static function failed(string $extractor, StoredFileContentFailure $failure): self
    {
        return new self(
            status: StoredFileContentStatus::Failed,
            extractor: $extractor,
            content: null,
            contentBytes: 0,
            isTruncated: false,
            failure: $failure,
        );
    }
}
