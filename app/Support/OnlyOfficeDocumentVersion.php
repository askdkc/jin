<?php

namespace App\Support;

use App\Models\StoredFile;

class OnlyOfficeDocumentVersion
{
    public function key(StoredFile $storedFile): string
    {
        return hash('sha256', implode(':', [
            (string) config('onlyoffice.instance_id'),
            (string) $storedFile->getKey(),
            strtolower((string) $storedFile->checksum),
            (string) $storedFile->size,
        ]));
    }
}
