<?php

namespace App\Enums;

enum StoredFileContentFailure: string
{
    case EncryptedDocument = 'encrypted_document';
    case UnsupportedFormat = 'unsupported_format';
    case SourceTooLarge = 'source_too_large';
    case OutputTooLarge = 'output_too_large';
    case SourceMissing = 'source_missing';
    case ChecksumMismatch = 'checksum_mismatch';
    case DispatchFailed = 'dispatch_failed';
    case ProcessTimeout = 'process_timeout';
    case ProcessFailed = 'process_failed';
    case SourceReadFailed = 'source_read_failed';
}
