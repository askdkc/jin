<?php

namespace App\Exceptions;

use App\Enums\StoredFileContentFailure;
use RuntimeException;
use Throwable;

class StoredFileContentExtractionException extends RuntimeException
{
    public function __construct(
        public readonly StoredFileContentFailure $failure,
        public readonly bool $retryable,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
