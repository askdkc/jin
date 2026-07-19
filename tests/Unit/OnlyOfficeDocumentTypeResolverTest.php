<?php

use App\Models\StoredFile;
use App\Support\OnlyOfficeDocumentTypeResolver;

test('resolves supported office extensions to the onlyoffice document type', function (string $extension, string $documentType) {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => "report.{$extension}",
        'mime_type' => 'application/octet-stream',
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))
        ->toBe([
            'fileType' => $extension,
            'documentType' => $documentType,
        ]);
})->with([
    ['xlsx', 'cell'],
    ['xls', 'cell'],
    ['xlsm', 'cell'],
    ['ods', 'cell'],
    ['docx', 'word'],
    ['doc', 'word'],
    ['odt', 'word'],
    ['pptx', 'slide'],
    ['ppt', 'slide'],
    ['odp', 'slide'],
]);

test('normalizes the extension to lowercase and accepts known office mime metadata', function () {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => 'REPORT.XLSX',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))
        ->toBe(['fileType' => 'xlsx', 'documentType' => 'cell']);
});

test('does not reject generic office upload mime metadata', function (string $mimeType) {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => 'report.xlsx',
        'mime_type' => $mimeType,
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))
        ->not->toBeNull();
})->with([
    'application/zip',
    'application/octet-stream',
]);

test('rejects unsupported files and obvious mime mismatches', function (string $name, string $mimeType) {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => $name,
        'mime_type' => $mimeType,
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))->toBeNull();
})->with([
    ['report.pdf', 'application/pdf'],
    ['photo.jpg', 'image/jpeg'],
    ['report.xlsx', 'image/png'],
    ['report.docx', 'video/mp4'],
    ['slides.pptx', 'text/plain'],
]);
