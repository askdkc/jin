<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\OnlyOfficeDocumentVersion;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

function onlyOfficePreviewProject(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    return compact('owner', 'team', 'project');
}

function configureOnlyOffice(bool $documentServerAvailable = true): void
{
    Http::fake([
        'http://onlyoffice-documentserver/healthcheck' => Http::response(
            $documentServerAvailable ? 'true' : 'false',
            $documentServerAvailable ? 200 : 503,
        ),
    ]);
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://onlyoffice-documentserver',
        'onlyoffice.public_url' => 'https://office.example.com',
        'onlyoffice.internal_url' => 'http://app.internal.test',
        'onlyoffice.jwt_secret' => 'test-onlyoffice-secret-0123456789012345',
        'onlyoffice.download_ttl' => 300,
        'onlyoffice.allow_download' => false,
        'onlyoffice.allow_print' => false,
        'onlyoffice.allow_copy' => true,
    ]);
}

test('authorized office config supports cell word and slide document types', function (string $extension, string $mimeType, string $documentType) {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/source.{$extension}",
        'original_name' => "source.{$extension}",
        'mime_type' => $mimeType,
        'size' => 123,
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $response = $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();

    expect($response->headers->get('cache-control'))
        ->toContain('private')
        ->toContain('no-store');

    $config = $response->json('config');
    $documentVersion = app(OnlyOfficeDocumentVersion::class)->key($file);
    parse_str((string) parse_url($config['document']['url'], PHP_URL_QUERY), $query);

    expect($response->json('documentServerUrl'))->toBe('https://office.example.com')
        ->and($config['type'])->toBe('embedded')
        ->and($config['documentType'])->toBe($documentType)
        ->and($config['width'])->toBe('100%')
        ->and($config['height'])->toBe('100%')
        ->and($config['document']['fileType'])->toBe($extension)
        ->and($config['document']['key'])->toBe($documentVersion)
        ->and($config['document']['url'])->toStartWith('http://app.internal.test/internal/onlyoffice/files/'.$file->id.'?')
        ->and($config['document']['url'])->toContain('version='.$documentVersion)
        ->and($query)->toHaveKeys(['expires', 'signature', 'version'])
        ->and((int) $query['expires'])->toBeGreaterThanOrEqual(now()->timestamp)
        ->and((int) $query['expires'])->toBeLessThanOrEqual(now()->timestamp + 301)
        ->and($config['document']['title'])->toBe('source.'.$extension)
        ->and($config['editorConfig']['mode'])->toBe('view')
        ->and($config['editorConfig']['lang'])->toBe('ja')
        ->and($config['editorConfig']['region'])->toBe('ja-JP');
})->with([
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cell'],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word'],
    ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slide'],
]);

test('office config is exactly read only and is signed without the root token', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => str_repeat('report-', 30).'<script>.xlsx',
        'mime_type' => 'application/octet-stream',
        'size' => 123,
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $response = $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();
    $config = $response->json('config');
    $token = $config['token'];
    unset($config['token']);

    $decoded = json_decode(
        json_encode(JWT::decode($token, new Key('test-onlyoffice-secret-0123456789012345', 'HS256'))),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect((array) $decoded)->toBe($config)
        ->and($config['document']['permissions'])->toBe([
            'edit' => false,
            'comment' => false,
            'review' => false,
            'fillForms' => false,
            'chat' => false,
            'protect' => false,
            'modifyContentControl' => false,
            'download' => false,
            'print' => false,
            'copy' => true,
        ])
        ->and($config['editorConfig']['user']['id'])->toBe(hash(
            'sha256',
            "jin-onlyoffice-user-v1\0{$owner->id}",
        ))
        ->and($config['editorConfig']['customization'])->toBe([
            'integrationMode' => 'embed',
            'macros' => false,
            'macrosMode' => 'disable',
            'plugins' => false,
            'feedback' => false,
            'help' => false,
            'comments' => false,
            'forcesave' => false,
        ])
        ->and($config)->not->toHaveKey('callbackUrl')
        ->and($config['document']['title'])->not->toContain('<')
        ->and($config['document']['title'])->toHaveLength(128);

    expect($response->getContent())
        ->not->toContain('test-onlyoffice-secret-0123456789012345')
        ->not->toContain('http://onlyoffice-documentserver');
});

test('disabled onlyoffice config returns a distinct fallback status', function () {
    Storage::fake('local');
    Queue::fake();
    config(['onlyoffice.enabled' => false]);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertNotFound();
});

test('enabled but misconfigured onlyoffice returns generic service unavailable', function () {
    Storage::fake('local');
    Queue::fake();
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'not-a-url',
        'onlyoffice.public_url' => 'https://office.example.com',
        'onlyoffice.internal_url' => 'http://app.internal.test',
        'onlyoffice.jwt_secret' => '',
    ]);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertStatus(503)
        ->assertJson(['message' => 'OnlyOffice preview is unavailable.']);
});

test('an unreachable internal document server returns a generic service unavailable error', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice(false);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertStatus(503)
        ->assertJson([
            'message' => 'OnlyOffice preview is unavailable.',
            'code' => 'document_server_unavailable',
        ]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'http://onlyoffice-documentserver/healthcheck');
});

test('unsupported onlyoffice files return unprocessable entity', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertStatus(422);
});

test('only project participants may request an office config', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $outsider = User::factory()->create();
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);

    $this->actingAs($outsider)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertForbidden();
});

test('config route explicitly scopes files to the requested project', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $otherProject, $file]))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, 999999]))
        ->assertNotFound();
});

test('a valid relative signature streams an office file without authentication', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => '確認資料.xlsx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $configResponse = $this->actingAs($owner)
        ->get(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();
    $downloadUrl = $configResponse->json('config.document.url');

    $response = $this->get($downloadUrl)
        ->assertOk()
        ->assertHeader('content-type', $file->mime_type)
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent('office bytes');

    expect($response->headers->get('cache-control'))
        ->toContain('private')
        ->toContain('no-store')
        ->and($response->headers->get('content-length'))->toBe('12')
        ->and($response->headers->get('content-disposition'))
        ->toStartWith('inline;')
        ->toContain("filename*=utf-8''".rawurlencode('確認資料.xlsx'));
});

test('disabling onlyoffice revokes an already issued signed download', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');
    $downloadUrl = URL::temporarySignedRoute(
        'onlyoffice.files.download',
        now()->addMinute(),
        [
            'stored_file' => $file,
            'version' => app(OnlyOfficeDocumentVersion::class)->key($file),
        ],
        absolute: false,
    );

    config(['onlyoffice.enabled' => false]);

    $this->get($downloadUrl)->assertNotFound();
});

test('expired signed downloads return forbidden', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);
    $expiredUrl = URL::temporarySignedRoute(
        'onlyoffice.files.download',
        now()->subMinute(),
        [
            'stored_file' => $file,
            'version' => app(OnlyOfficeDocumentVersion::class)->key($file),
        ],
        absolute: false,
    );

    $this->get($expiredUrl)->assertForbidden();
});

test('a valid signed download rejects a stale document version', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/octet-stream',
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');
    $staleUrl = URL::temporarySignedRoute(
        'onlyoffice.files.download',
        now()->addMinute(),
        ['stored_file' => $file, 'version' => str_repeat('a', 64)],
        absolute: false,
    );

    $this->get($staleUrl)->assertStatus(410);
});

test('file detail exposes onlyoffice capability without loading a viewer on the server', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/report.xlsx",
        'original_name' => 'report.xlsx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
    Storage::disk('local')->put($file->path, 'office bytes');

    $this->actingAs($owner)
        ->get(route('projects.files.show', [$team, $project, $file]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/files/Show')
            ->where('onlyOffice.enabled', true)
            ->where('onlyOffice.supported', true)
            ->where(
                'onlyOffice.configUrl',
                route('projects.files.onlyoffice.config', [$team, $project, $file], absolute: false),
            ));
});

test('onlyoffice frontend loads the api lazily and provides cleanup and fallback', function () {
    $componentPath = resource_path('js/components/OnlyOfficePreviewDialog.svelte');

    expect(file_exists($componentPath))->toBeTrue();

    $component = file_get_contents($componentPath);
    $show = file_get_contents(resource_path('js/pages/projects/files/Show.svelte'));
    $materials = file_get_contents(resource_path('js/pages/projects/materials/Index.svelte'));

    expect($component)
        ->toContain('web-apps/apps/api/documents/api.js')
        ->toContain("document.createElement('script')")
        ->toContain('script.dataset.onlyofficeApi === source')
        ->toContain('script.remove()')
        ->toContain("typeof window !== 'undefined'")
        ->toContain('DocsAPI.DocEditor')
        ->toContain('destroyEditor()')
        ->toContain('onDestroy')
        ->toContain('fetch(configUrl')
        ->toContain('min-h-[70dvh]')
        ->toContain('role="alert"')
        ->toContain('onFallback')
        ->and($show)
        ->toContain('OnlyOfficePreviewDialog')
        ->toContain('onlyOffice.supported')
        ->toContain('onlyOffice.enabled')
        ->and($materials)
        ->not->toContain('OnlyOfficePreviewDialog')
        ->not->toContain('web-apps/apps/api/documents/api.js');
});

test('the signed download route has no arbitrary path parameter', function () {
    $route = app('router')->getRoutes()->getByName('onlyoffice.files.download');

    expect($route)->not->toBeNull()
        ->and($route->parameterNames())->toBe(['stored_file']);
});
