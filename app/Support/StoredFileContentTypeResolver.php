<?php

namespace App\Support;

use App\Models\StoredFile;
use Illuminate\Support\Str;

final class StoredFileContentTypeResolver
{
    /** @var list<string> */
    public const SUPPORTED_EXTENSIONS = [
        'docx',
        'xlsx',
        'pptx',
        'pdf',
    ];

    public function __construct(
        private OnlyOfficeDocumentTypeResolver $onlyOfficeDocumentTypeResolver,
    ) {}

    /** @return array{extension: string, extractor: string}|null */
    public function resolve(StoredFile $storedFile): ?array
    {
        $extension = Str::lower(pathinfo($storedFile->original_name, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return null;
        }

        if ($extension === 'pdf') {
            $mimeType = Str::lower(trim(Str::before((string) $storedFile->mime_type, ';')));

            return in_array($mimeType, ['application/pdf', 'application/x-pdf'], true)
                ? ['extension' => $extension, 'extractor' => 'markitdown']
                : null;
        }

        if ($this->onlyOfficeDocumentTypeResolver->resolve($storedFile) === null) {
            return null;
        }

        return [
            'extension' => $extension,
            'extractor' => 'markitdown',
        ];
    }

    public function supports(StoredFile $storedFile): bool
    {
        return $this->resolve($storedFile) !== null;
    }
}
