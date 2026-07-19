<?php

use App\Models\StoredFile;
use App\Support\OnlyOfficeDocumentVersion;

test('document versions are stable across rename and use only official key characters', function () {
    $file = (new StoredFile)->setRawAttributes([
        'id' => 42,
        'path' => 'projects/42/random/report.xlsx',
        'original_name' => 'report.xlsx',
        'size' => 1234,
    ]);

    $versionService = app(OnlyOfficeDocumentVersion::class);
    $originalVersion = $versionService->key($file);

    $file->original_name = 'renamed.xlsx';

    expect($originalVersion)
        ->toHaveLength(64)
        ->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($versionService->key($file))->toBe($originalVersion);
});

test('document versions change when the immutable source path or size changes', function () {
    $file = (new StoredFile)->setRawAttributes([
        'id' => 42,
        'path' => 'projects/42/random/report.xlsx',
        'original_name' => 'report.xlsx',
        'size' => 1234,
    ]);
    $versionService = app(OnlyOfficeDocumentVersion::class);
    $originalVersion = $versionService->key($file);

    $file->path = 'projects/42/random/replacement.xlsx';
    $pathVersion = $versionService->key($file);
    $file->path = 'projects/42/random/report.xlsx';
    $file->size = 1235;
    $sizeVersion = $versionService->key($file);

    expect($pathVersion)->not->toBe($originalVersion)
        ->and($sizeVersion)->not->toBe($originalVersion)
        ->and($sizeVersion)->not->toBe($pathVersion);
});
