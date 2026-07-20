<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('Laravel accepts a config only when the real OnlyOffice server is available', function () {
    Storage::fake('local');
    Queue::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/integration.docx",
        'original_name' => 'integration.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size' => 12,
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
