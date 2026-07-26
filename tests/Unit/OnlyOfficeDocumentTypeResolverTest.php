<?php

use App\Models\StoredFile;
use App\Support\OnlyOfficeDocumentTypeResolver;

test('resolves only matching office extensions and mime types', function (
    string $extension,
    string $mimeType,
    string $documentType,
    string $contentType,
) {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => "report.{$extension}",
        'mime_type' => $mimeType,
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))->toBe([
        'fileType' => $extension,
        'documentType' => $documentType,
        'contentType' => $contentType,
    ]);
})->with([
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cell', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slide', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ['odt', 'application/vnd.oasis.opendocument.text', 'word', 'application/vnd.oasis.opendocument.text'],
    ['ods', 'application/vnd.oasis.opendocument.spreadsheet', 'cell', 'application/vnd.oasis.opendocument.spreadsheet'],
    ['odp', 'application/vnd.oasis.opendocument.presentation', 'slide', 'application/vnd.oasis.opendocument.presentation'],
]);

test('normalizes extension and mime casing and ignores mime parameters', function () {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => 'REPORT.XLSX',
        'mime_type' => 'APPLICATION/VND.OPENXMLFORMATS-OFFICEDOCUMENT.SPREADSHEETML.SHEET ; charset=binary',
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))
        ->toMatchArray(['fileType' => 'xlsx', 'documentType' => 'cell']);
});

test('exposes the compatibility registry used to resolve office files', function () {
    $resolver = app(OnlyOfficeDocumentTypeResolver::class);
    $formats = $resolver->formats();

    expect(array_keys($formats))->toBe([
        'doc',
        'docx',
        'odt',
        'xls',
        'xlsx',
        'xlsm',
        'ods',
        'ppt',
        'pptx',
        'odp',
    ]);

    foreach ($formats as $extension => $format) {
        $file = (new StoredFile)->setRawAttributes([
            'original_name' => "report.{$extension}",
            'mime_type' => $format['mimes'][0],
        ]);

        expect($resolver->resolve($file))->toMatchArray([
            'fileType' => $extension,
            'documentType' => $format['documentType'],
            'contentType' => $format['contentType'],
        ]);
    }

    $archive = (new StoredFile)->setRawAttributes([
        'original_name' => 'archive.zip',
        'mime_type' => 'application/zip',
    ]);

    expect($resolver->resolve($archive))->toBeNull();
});

test('rejects unsupported files and extension mime mismatches', function (string $name, ?string $mimeType) {
    $file = (new StoredFile)->setRawAttributes([
        'original_name' => $name,
        'mime_type' => $mimeType,
    ]);

    expect(app(OnlyOfficeDocumentTypeResolver::class)->resolve($file))->toBeNull();
})->with([
    ['report.pdf', 'application/pdf'],
    ['report.xlsx', 'application/octet-stream'],
    ['report.xlsx', 'image/png'],
    ['report.docx', 'video/mp4'],
    ['report.xlsx', "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\t; charset=binary"],
    ['slides.pptx', 'text/plain'],
    ['slides.pptx', null],
]);
