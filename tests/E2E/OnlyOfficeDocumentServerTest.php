<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\OnlyOfficeTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

test('OnlyOffice downloads the signed original and renders a real Office document', function () {
    if (! filter_var(env('ONLYOFFICE_E2E', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set ONLYOFFICE_E2E=true and start DocumentServer to run this test.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'jin-onlyoffice-');
    $zip = new ZipArchive;

    expect($temporaryPath)->not->toBeFalse()
        ->and($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
    $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>Jin ONLYOFFICE integration test</w:t></w:r></w:p>
    <w:sectPr/>
  </w:body>
</w:document>
XML);
    $zip->close();

    $contents = file_get_contents($temporaryPath);
    @unlink($temporaryPath);
    expect($contents)->not->toBeFalse();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $path = "projects/{$project->id}/onlyoffice-e2e.docx";

    expect(Storage::disk('local')->put($path, $contents))->toBeTrue();

    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $path,
        'original_name' => 'onlyoffice-e2e.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
    ]);

    $configResponse = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();

    $document = $configResponse->json('config.document');
    expect($document)->toBeArray()
        ->and($document['fileType'] ?? null)->toBe('docx')
        ->and($document['url'] ?? null)->toStartWith((string) config('onlyoffice.internal_url'));

    $request = [
        'async' => false,
        'filetype' => 'docx',
        'key' => $document['key'],
        'outputtype' => 'pdf',
        'title' => 'onlyoffice-e2e.docx',
        'url' => $document['url'],
    ];
    $request['token'] = app(OnlyOfficeTokenService::class)->encode($request);
    $conversionUrl = rtrim((string) config('onlyoffice.document_server_url'), '/')
        .'/converter?shardkey='.rawurlencode($document['key']);
    $conversionResponse = Http::acceptJson()
        ->connectTimeout(10)
        ->timeout(180)
        ->post($conversionUrl, $request);

    $this->assertTrue($conversionResponse->successful(), $conversionResponse->body());

    $conversion = $conversionResponse->json();
    $this->assertIsArray($conversion, $conversionResponse->body());
    $this->assertSame(0, $conversion['error'] ?? 0, $conversionResponse->body());
    $this->assertTrue($conversion['end'] ?? false, $conversionResponse->body());
    $this->assertIsString($conversion['fileUrl'] ?? null, $conversionResponse->body());

    $pdfResponse = Http::connectTimeout(10)
        ->timeout(60)
        ->get($conversion['fileUrl']);

    $this->assertTrue($pdfResponse->successful(), $pdfResponse->body());
    expect($pdfResponse->body())->toStartWith('%PDF-');
});
