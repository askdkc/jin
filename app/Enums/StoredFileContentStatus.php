<?php

namespace App\Enums;

enum StoredFileContentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Protected = 'protected';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
}
