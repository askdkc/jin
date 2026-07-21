<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('Laravel accepts a config when the OnlyOffice healthcheck succeeds', function () {
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'http://onlyoffice-documentserver/healthcheck' => Http::response('true'),
    ]);
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://onlyoffice-documentserver',
        'onlyoffice.public_url' => 'https://office.example.com',
        'onlyoffice.internal_url' => 'http://app.internal.test',
        'onlyoffice.jwt_secret' => 'test-onlyoffice-secret-0123456789012345',
        'onlyoffice.instance_id' => 'jin-integration-test',
        'onlyoffice.download_ttl' => 300,
        'onlyoffice.healthcheck_timeout' => 3,
        'onlyoffice.max_file_size' => 26214400,
    ]);

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/integration.docx",
        'original_name' => 'integration.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => 12,
        'checksum' => hash('sha256', 'office bytes'),
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $response = $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();

    expect($response->json('documentServerUrl'))
        ->toBe(config('onlyoffice.public_url'))
        ->and($response->json('config.documentType'))->toBe('word')
        ->and($response->json('config.token'))->toBeString()->not->toBeEmpty();
});
