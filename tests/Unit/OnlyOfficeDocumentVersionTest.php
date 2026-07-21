<?php

use App\Models\StoredFile;
use App\Support\OnlyOfficeDocumentVersion;
use Tests\TestCase;

uses(TestCase::class);

test('document versions are stable across rename and use only official key characters', function () {
    $file = (new StoredFile)->setRawAttributes([
        'id' => 42,
        'path' => 'projects/42/random/report.xlsx',
        'original_name' => 'report.xlsx',
        'size' => 1234,
        'checksum' => str_repeat('a', 64),
    ]);

    $versionService = app(OnlyOfficeDocumentVersion::class);
    $originalVersion = $versionService->key($file);

    $file->original_name = 'renamed.xlsx';

    expect($originalVersion)
        ->toHaveLength(64)
        ->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($versionService->key($file))->toBe($originalVersion);
});

test('document versions change when the checksum size or instance changes', function () {
    $file = (new StoredFile)->setRawAttributes([
        'id' => 42,
        'path' => 'projects/42/random/report.xlsx',
        'original_name' => 'report.xlsx',
        'size' => 1234,
        'checksum' => str_repeat('a', 64),
    ]);
    $versionService = app(OnlyOfficeDocumentVersion::class);
    $originalVersion = $versionService->key($file);

    $file->checksum = str_repeat('b', 64);
    $checksumVersion = $versionService->key($file);
    $file->checksum = str_repeat('a', 64);
    $file->size = 1235;
    $sizeVersion = $versionService->key($file);
    $file->size = 1234;
    config(['onlyoffice.instance_id' => 'another-instance']);
    $instanceVersion = $versionService->key($file);

    expect($checksumVersion)->not->toBe($originalVersion)
        ->and($sizeVersion)->not->toBe($originalVersion)
        ->and($instanceVersion)->not->toBe($originalVersion);
});
