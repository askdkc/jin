<?php

use App\Models\StoredFile;
use App\Support\OnlyOfficeDocumentTypeResolver;
use App\Support\StoredFileContentTypeResolver;

function resolveStoredFileContentType(string $name, ?string $mimeType): ?array
{
    $storedFile = new StoredFile([
        'original_name' => $name,
        'mime_type' => $mimeType,
    ]);

    return (new StoredFileContentTypeResolver(
        new OnlyOfficeDocumentTypeResolver,
    ))->resolve($storedFile);
}

test('supported file types resolve to the selected extractor', function (
    string $extension,
    string $mimeType,
    string $extractor,
) {
    expect(resolveStoredFileContentType("report.{$extension}", $mimeType))->toBe([
        'extension' => $extension,
        'extractor' => $extractor,
    ]);
})->with([
    'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'markitdown'],
    'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'markitdown'],
    'pptx' => ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'markitdown'],
    'pdf' => ['pdf', 'application/pdf', 'markitdown'],
]);

test('unsupported extensions and mismatched MIME types remain filename only', function (
    string $name,
    ?string $mimeType,
) {
    expect(resolveStoredFileContentType($name, $mimeType))->toBeNull();
})->with([
    'legacy Word' => ['report.doc', 'application/msword'],
    'legacy Excel' => ['report.xls', 'application/vnd.ms-excel'],
    'legacy PowerPoint' => ['report.ppt', 'application/vnd.ms-powerpoint'],
    'macro workbook' => ['report.xlsm', 'application/vnd.ms-excel.sheet.macroenabled.12'],
    'open document text' => ['report.odt', 'application/vnd.oasis.opendocument.text'],
    'open document sheet' => ['report.ods', 'application/vnd.oasis.opendocument.spreadsheet'],
    'open document slides' => ['report.odp', 'application/vnd.oasis.opendocument.presentation'],
    'office MIME mismatch' => ['report.docx', 'text/plain'],
    'pdf MIME mismatch' => ['report.pdf', 'application/octet-stream'],
    'missing MIME' => ['report.pdf', null],
]);
