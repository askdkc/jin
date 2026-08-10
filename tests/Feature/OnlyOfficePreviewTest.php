<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\OnlyOfficeDocumentVersion;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
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

function configureOnlyOffice(mixed $healthResponse = null): void
{
    Http::preventStrayRequests();
    Http::fake([
        'http://onlyoffice-documentserver/healthcheck' => $healthResponse
            ?? Http::response('true'),
    ]);
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://onlyoffice-documentserver',
        'onlyoffice.public_url' => 'https://office.example.com',
        'onlyoffice.internal_url' => 'http://app.internal.test',
        'onlyoffice.jwt_secret' => 'test-onlyoffice-secret-0123456789012345',
        'onlyoffice.instance_id' => 'jin-test',
        'onlyoffice.download_ttl' => 300,
        'onlyoffice.healthcheck_timeout' => 3,
        'onlyoffice.max_file_size' => 26214400,
        'onlyoffice.allow_download' => false,
        'onlyoffice.allow_print' => false,
        'onlyoffice.allow_copy' => true,
        'inertia.ssr.enabled' => false,
    ]);
}

function createOfficeFile(
    Project $project,
    User $owner,
    string $extension = 'docx',
    string $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    string $contents = 'office bytes',
    ?string $originalName = null,
): StoredFile {
    $path = "projects/{$project->id}/office-".uniqid().".{$extension}";
    Storage::disk('local')->put($path, $contents);

    return StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => $path,
        'original_name' => $originalName ?? "source.{$extension}",
        'mime_type' => $mimeType,
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
    ]);
}

test('authorized office config supports word cell and slide documents', function (string $extension, string $mimeType, string $documentType) {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner, $extension, $mimeType);

    $response = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();

    expect($response->headers->get('cache-control'))->toContain('private')->toContain('no-store')
        ->and($response->json('documentServerUrl'))->toBe('https://office.example.com')
        ->and($response->json('config.type'))->toBe('embedded')
        ->and($response->json('config.documentType'))->toBe($documentType)
        ->and($response->json('config.document.fileType'))->toBe($extension)
        ->and($response->json('config.document.key'))->toHaveLength(64)
        ->and($response->json('config.editorConfig.mode'))->toBe('view');
})->with([
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word'],
    ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'cell'],
    ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slide'],
]);

test('office editor language and region follow the selected application locale', function (string $locale, string $language, string $region) {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);

    $response = $this->withSession(['locale' => $locale])
        ->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();

    expect($response->json('config.editorConfig.lang'))->toBe($language)
        ->and($response->json('config.editorConfig.region'))->toBe($region);
})->with([
    ['en', 'en', 'en-US'],
    ['ja', 'ja', 'ja-JP'],
    ['fr', 'fr', 'fr-FR'],
    ['de', 'de', 'de-DE'],
    ['zh_CN', 'zh', 'zh-CN'],
    ['zh_TW', 'zh-TW', 'zh-TW'],
    ['es', 'es', 'es-ES'],
    ['pt_BR', 'pt', 'pt-BR'],
    ['pt_PT', 'pt-PT', 'pt-PT'],
    ['ko', 'ko', 'ko-KR'],
]);

test('the complete read only config and expiry claims are signed with HS256', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile(
        $project,
        $owner,
        originalName: "../folder/\x01quarterly report.docx",
    );

    $response = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertOk();
    $config = $response->json('config');
    $decoded = json_decode(json_encode(JWT::decode(
        $config['token'],
        new Key('test-onlyoffice-secret-0123456789012345', 'HS256'),
    )), true, flags: JSON_THROW_ON_ERROR);
    parse_str((string) parse_url($config['document']['url'], PHP_URL_QUERY), $query);

    expect(Arr::except($decoded, ['iat', 'nbf', 'exp']))->toBe(Arr::except($config, ['token']))
        ->and($decoded)->toHaveKeys(['iat', 'nbf', 'exp'])
        ->and($decoded['iat'])->toBe($decoded['nbf'])
        ->and($decoded['exp'])->toBe((int) $query['expires'])
        ->and($config['document']['permissions'])->toBe([
            'chat' => false,
            'comment' => false,
            'copy' => true,
            'download' => false,
            'edit' => false,
            'fillForms' => false,
            'modifyContentControl' => false,
            'modifyFilter' => false,
            'print' => false,
            'protect' => false,
            'review' => false,
        ])
        ->and($config['editorConfig']['coEditing'])->toBe(['mode' => 'strict', 'change' => false])
        ->and($config['editorConfig'])->not->toHaveKey('user')
        ->and($config)->not->toHaveKey('callbackUrl')
        ->and($config['document']['title'])->toBe('quarterly report.docx')
        ->and($query)->toHaveKeys(['expires', 'viewer', 'version', 'signature'])
        ->and(parse_url($config['document']['url'], PHP_URL_PATH))->toEndWith('/'.$file->id)
        ->and((int) $query['viewer'])->toBe($owner->id);

    expect($response->getContent())
        ->not->toContain('test-onlyoffice-secret-0123456789012345')
        ->not->toContain('http://onlyoffice-documentserver');
});

test('config endpoint requires authentication and current project access', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);
    $outsider = User::factory()->create();
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);

    $this->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertUnauthorized();
    $this->actingAs($outsider)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertForbidden();
});

test('config endpoint does not expose absent database records', function () {
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, 999999]))
        ->assertNotFound();
});

test('config endpoint rejects extension mime mismatches', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner, 'xlsx', 'application/octet-stream');

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertUnprocessable();
    Http::assertNothingSent();
});

test('config endpoint rejects files over the configured size', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    config(['onlyoffice.max_file_size' => 5]);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner, contents: '123456');

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertStatus(413);
    Http::assertNothingSent();
});

test('config endpoint rejects a missing storage object', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'source.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertNotFound();
    Http::assertNothingSent();
});

test('disabled invalid or incomplete onlyoffice configuration returns service unavailable', function (array $override) {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    config($override);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'OnlyOffice preview is unavailable.']);
    Http::assertNothingSent();
})->with([
    [['onlyoffice.enabled' => false]],
    [['onlyoffice.jwt_secret' => 'too-short']],
    [['onlyoffice.internal_url' => 'http://user@app.internal.test']],
    [['onlyoffice.public_url' => 'https://office.example.com?redirect=1']],
    [['onlyoffice.document_server_url' => 'file:///tmp/document-server']],
    [['onlyoffice.document_server_url' => 'http://onlyoffice-documentserver:99999']],
]);

test('the checksum backfill streams existing files and reports missing files', function () {
    Storage::fake('local');
    Queue::fake();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $available = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/available.docx',
            'original_name' => 'available.docx',
            'checksum' => null,
            'size' => 999,
        ]));
    $missing = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($owner, 'uploader')
        ->create([
            'path' => 'projects/test/missing.docx',
            'original_name' => 'missing.docx',
            'checksum' => null,
        ]));
    Storage::disk('local')->put($available->path, 'existing office bytes');

    $this->artisan('stored-files:backfill-checksums', ['--chunk' => 1])
        ->expectsOutputToContain('Updated 1 stored file checksums; skipped 1')
        ->assertExitCode(1);

    expect($available->refresh()->checksum)->toBe(hash('sha256', 'existing office bytes'))
        ->and($available->size)->toBe(strlen('existing office bytes'))
        ->and($missing->refresh()->checksum)->toBeNull();
});

test('document server health failures redirects and timeouts return service unavailable', function (string $failure) {
    Storage::fake('local');
    Queue::fake();
    $healthResponse = match ($failure) {
        'failure' => Http::response('false', 503),
        'redirect' => Http::response('', 302, ['Location' => 'http://redirect.invalid/healthcheck']),
        'timeout' => fn () => throw new ConnectionException('timeout'),
    };
    configureOnlyOffice($healthResponse);
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);

    $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->assertServiceUnavailable();
    Http::assertSentCount($failure === 'timeout' ? 0 : 1);
})->with([
    'failure',
    'redirect',
    'timeout',
]);

test('a signed internal URL streams the original without changing it', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner, contents: 'immutable office bytes', originalName: '確認資料.docx');
    $url = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->json('config.document.url');

    $response = $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent('immutable office bytes');

    expect($response->headers->get('cache-control'))->toContain('private')->toContain('no-store')
        ->and($response->headers->get('content-disposition'))->toContain("filename*=utf-8''".rawurlencode('確認資料.docx'))
        ->and(Storage::disk('local')->get($file->path))->toBe('immutable office bytes');
});

test('an issued URL is revoked when project access is removed', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $viewer = User::factory()->create();
    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    $project->members()->attach($viewer);
    $file = createOfficeFile($project, $owner);
    $url = $this->actingAs($viewer)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->json('config.document.url');
    $project->members()->detach($viewer);

    $this->get($url)->assertForbidden();
});

test('an issued URL is revoked after the binary version changes', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);
    $url = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->json('config.document.url');
    Storage::disk('local')->put($file->path, 'new binary');
    $file->update(['size' => 10, 'checksum' => hash('sha256', 'new binary')]);

    $this->get($url)->assertStatus(410);
});

test('an issued URL is revoked by the kill switch', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);
    $url = $this->actingAs($owner)
        ->getJson(route('projects.files.onlyoffice.config', [$team, $project, $file]))
        ->json('config.document.url');
    config(['onlyoffice.enabled' => false]);

    $this->get($url)->assertServiceUnavailable();
});

test('expired tampered and unsigned internal URLs are rejected before file lookup', function () {
    configureOnlyOffice();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'source.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    $parameters = [
        'file' => $file->id,
        'viewer' => $owner->id,
        'version' => app(OnlyOfficeDocumentVersion::class)->key($file),
    ];
    $expired = URL::temporarySignedRoute(
        'files.onlyoffice.internal-download',
        now()->subMinute(),
        $parameters,
        absolute: false,
    );
    $valid = URL::temporarySignedRoute(
        'files.onlyoffice.internal-download',
        now()->addMinute(),
        $parameters,
        absolute: false,
    );

    $this->get($expired)->assertForbidden();
    $this->get($valid.'&version='.str_repeat('b', 64))->assertForbidden();
    $this->get('/internal/onlyoffice/files/'.$file->id)->assertForbidden();
    $this->get('/internal/onlyoffice/files/999999')->assertForbidden();
});

test('a valid signature with an obsolete content key is rejected', function () {
    configureOnlyOffice();
    ['owner' => $owner, 'project' => $project] = onlyOfficePreviewProject();
    $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'original_name' => 'source.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    $url = URL::temporarySignedRoute(
        'files.onlyoffice.internal-download',
        now()->addMinute(),
        ['file' => $file->id, 'viewer' => $owner->id, 'version' => str_repeat('b', 64)],
        absolute: false,
    );

    $this->get($url)->assertStatus(410);
});

test('file detail exposes onlyoffice without initializing it on the server', function () {
    Storage::fake('local');
    Queue::fake();
    configureOnlyOffice();
    ['owner' => $owner, 'team' => $team, 'project' => $project] = onlyOfficePreviewProject();
    $file = createOfficeFile($project, $owner);

    $this->actingAs($owner)
        ->get(route('projects.files.show', [$team, $project, $file]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/files/Show')
            ->where('onlyOffice.enabled', true)
            ->where('onlyOffice.supported', true)
            ->where('onlyOffice.configUrl', route(
                'projects.files.onlyoffice.config',
                [$team, $project, $file],
                absolute: false,
            )));
});

test('onlyoffice frontend renders inline and in the dialog with cleanup and a download fallback', function () {
    $component = file_get_contents(resource_path('js/components/OnlyOfficePreview.svelte'));
    $dialog = file_get_contents(resource_path('js/components/OnlyOfficePreviewDialog.svelte'));
    $storedFileDialog = file_get_contents(resource_path('js/components/StoredFilePreviewDialog.svelte'));
    $show = file_get_contents(resource_path('js/pages/projects/files/Show.svelte'));

    expect($component)
        ->toContain('web-apps/apps/api/documents/api.js')
        ->toContain('onlyOfficeApiPromise')
        ->toContain('DocsAPI.DocEditor')
        ->toContain('destroyEditor()')
        ->toContain('onDestroy(cleanupPreview)')
        ->toContain('fetch(configUrl')
        ->toContain('role="alert"')
        ->toContain('ProjectFileController.download.url')
        ->and($dialog)
        ->toContain('<OnlyOfficePreview')
        ->toContain('active={open}')
        ->toContain('min-h-[70dvh]')
        ->and($storedFileDialog)
        ->toContain('isOnlyOfficeStoredFile')
        ->toContain('<OnlyOfficePreviewDialog')
        ->toContain('OnlyOfficePreviewController.url')
        ->and($show)
        ->toContain("previewKind === 'onlyoffice'")
        ->toContain('<OnlyOfficePreview')
        ->toContain('active={!onlyOfficeOpen}')
        ->toContain('onlyOffice.enabled')
        ->toContain('onlyOffice.supported');
});

test('the internal route has only an unbound file identifier', function () {
    $route = app('router')->getRoutes()->getByName('files.onlyoffice.internal-download');

    expect($route)->not->toBeNull()
        ->and($route->parameterNames())->toBe(['file']);
});
