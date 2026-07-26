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
    'doc' => ['doc', 'application/msword', 'antiword'],
    'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'markitdown'],
    'xls' => ['xls', 'application/vnd.ms-excel', 'xls2csv'],
    'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'markitdown'],
    'ppt' => ['ppt', 'application/vnd.ms-powerpoint', 'catppt'],
    'pptx' => ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'markitdown'],
    'pdf' => ['pdf', 'application/pdf', 'markitdown'],
]);

test('unsupported extensions and mismatched MIME types remain filename only', function (
    string $name,
    ?string $mimeType,
) {
    expect(resolveStoredFileContentType($name, $mimeType))->toBeNull();
})->with([
    'macro workbook' => ['report.xlsm', 'application/vnd.ms-excel.sheet.macroenabled.12'],
    'open document text' => ['report.odt', 'application/vnd.oasis.opendocument.text'],
    'open document sheet' => ['report.ods', 'application/vnd.oasis.opendocument.spreadsheet'],
    'open document slides' => ['report.odp', 'application/vnd.oasis.opendocument.presentation'],
    'office MIME mismatch' => ['report.docx', 'text/plain'],
    'pdf MIME mismatch' => ['report.pdf', 'application/octet-stream'],
    'missing MIME' => ['report.pdf', null],
]);
