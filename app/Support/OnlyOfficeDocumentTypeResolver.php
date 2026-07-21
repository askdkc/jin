<?php

namespace App\Support;

use App\Models\StoredFile;
use Illuminate\Support\Str;

class OnlyOfficeDocumentTypeResolver
{
    /** @var array<string, array{documentType: string, contentType: string, mimes: list<string>}> */
    private const FORMATS = [
        'doc' => [
            'documentType' => 'word',
            'contentType' => 'application/msword',
            'mimes' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
        ],
        'docx' => [
            'documentType' => 'word',
            'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        ],
        'odt' => [
            'documentType' => 'word',
            'contentType' => 'application/vnd.oasis.opendocument.text',
            'mimes' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        ],
        'xls' => [
            'documentType' => 'cell',
            'contentType' => 'application/vnd.ms-excel',
            'mimes' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage'],
        ],
        'xlsx' => [
            'documentType' => 'cell',
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ],
        'xlsm' => [
            'documentType' => 'cell',
            'contentType' => 'application/vnd.ms-excel.sheet.macroenabled.12',
            'mimes' => ['application/vnd.ms-excel.sheet.macroenabled.12', 'application/zip'],
        ],
        'ods' => [
            'documentType' => 'cell',
            'contentType' => 'application/vnd.oasis.opendocument.spreadsheet',
            'mimes' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        ],
        'ppt' => [
            'documentType' => 'slide',
            'contentType' => 'application/vnd.ms-powerpoint',
            'mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage'],
        ],
        'pptx' => [
            'documentType' => 'slide',
            'contentType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        ],
        'odp' => [
            'documentType' => 'slide',
            'contentType' => 'application/vnd.oasis.opendocument.presentation',
            'mimes' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        ],
    ];

    /** @return array{fileType: string, documentType: string, contentType: string}|null */
    public function resolve(StoredFile $storedFile): ?array
    {
        $fileType = Str::lower(pathinfo($storedFile->original_name, PATHINFO_EXTENSION));
        $format = self::FORMATS[$fileType] ?? null;
        $mimeType = Str::lower(trim(Str::before((string) $storedFile->mime_type, ';')));

        if ($format === null || ! in_array($mimeType, $format['mimes'], true)) {
            return null;
        }

        return [
            'fileType' => $fileType,
            'documentType' => $format['documentType'],
            'contentType' => $format['contentType'],
        ];
    }

    public function supports(StoredFile $storedFile): bool
    {
        return $this->resolve($storedFile) !== null;
    }
}
