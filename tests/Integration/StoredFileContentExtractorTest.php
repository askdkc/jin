<?php

use App\Enums\StoredFileContentStatus;
use App\Models\StoredFile;
use App\Support\StoredFileContentExtractor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function realExtractorFixture(string $name, string $mimeType): StoredFile
{
    $fixturePath = base_path("tests/Fixtures/StoredFileContent/files/{$name}");
    $contents = file_get_contents($fixturePath);

    expect($contents)->not->toBeFalse();

    $storagePath = 'extractor-integration/'.Str::uuid().'/'.$name;
    Storage::disk('local')->put($storagePath, $contents);

    return (new StoredFile)->forceFill([
        'path' => $storagePath,
        'disk' => 'local',
        'original_name' => $name,
        'mime_type' => $mimeType,
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
    ]);
}

function requireRealExtractors(): void
{
    if (! filter_var(env('STORED_FILE_CONTENT_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped(
            'Set STORED_FILE_CONTENT_INTEGRATION=true and install the extractor commands to run this test.',
        );
    }
}

test('real extractors publish searchable text for every supported extension', function (
    string $name,
    string $mimeType,
    string $expectedExtractor,
) {
    requireRealExtractors();
    Storage::fake('local');
    $storedFile = realExtractorFixture($name, $mimeType);

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Ready)
        ->and($result->extractor)->toBe($expectedExtractor)
        ->and($result->content)->toContain('24680');
})->with([
    'DOCX through MarkItDown' => [
        'supported.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'markitdown',
    ],
    'XLSX through MarkItDown' => [
        'supported.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'markitdown',
    ],
    'PPTX through MarkItDown' => [
        'supported.pptx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'markitdown',
    ],
    'PDF through MarkItDown' => [
        'supported.pdf',
        'application/pdf',
        'markitdown',
    ],
]);

test('real extractors publish no content for protected files', function (
    string $name,
    string $mimeType,
) {
    requireRealExtractors();
    Storage::fake('local');
    $storedFile = realExtractorFixture($name, $mimeType);

    $result = app(StoredFileContentExtractor::class)->extract(
        $storedFile,
        $storedFile->checksum,
    );

    expect($result->status)->toBe(StoredFileContentStatus::Protected)
        ->and($result->content)->toBeNull()
        ->and($result->contentBytes)->toBe(0);
})->with([
    'protected DOCX' => [
        'protected.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ],
    'protected PDF' => [
        'protected.pdf',
        'application/pdf',
    ],
]);
