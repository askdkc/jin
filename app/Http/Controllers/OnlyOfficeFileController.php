<?php

namespace App\Http\Controllers;

use App\Models\StoredFile;
use App\Models\User;
use App\Support\OnlyOfficeConfigService;
use App\Support\OnlyOfficeDocumentTypeResolver;
use App\Support\OnlyOfficeDocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnlyOfficeFileController extends Controller
{
    public function __construct(
        private OnlyOfficeConfigService $configService,
        private OnlyOfficeDocumentTypeResolver $documentTypeResolver,
        private OnlyOfficeDocumentVersion $documentVersion,
    ) {}

    public function __invoke(Request $request, string $file): Response
    {
        abort_unless(
            (bool) config('onlyoffice.enabled', false) && $this->configService->isConfigured(),
            503,
        );

        $viewer = User::query()->find($request->integer('viewer'));
        abort_unless($viewer !== null, 403);

        $storedFile = StoredFile::query()->with('project.team')->find($file);
        abort_unless($storedFile !== null, 404);
        Gate::forUser($viewer)->authorize('view', $storedFile->project);

        $resolved = $this->documentTypeResolver->resolve($storedFile);
        abort_unless($resolved !== null, 422);

        $requestedVersion = $request->string('version')->toString();
        abort_unless(
            $requestedVersion !== ''
                && hash_equals($this->documentVersion->key($storedFile), $requestedVersion),
            410,
        );

        $disk = Storage::disk($storedFile->disk);
        abort_unless($disk->exists($storedFile->path), 404);
        $stream = $disk->readStream($storedFile->path);
        abort_unless(is_resource($stream), 404);

        $response = new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        });
        $filename = $this->safeFilename($storedFile->original_name);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition('inline', $filename, $this->fallbackFilename($filename)),
        );
        $response->headers->set('Content-Type', $resolved['contentType']);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Length', (string) $storedFile->size);

        return $response;
    }

    private function fallbackFilename(string $filename): string
    {
        $fallback = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($filename)) ?? '', '._-');

        return $fallback !== '' ? $fallback : 'document';
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        $filename = Str::limit(trim($filename, " .\t\n\r\0\x0B"), 128, '');

        return $filename !== '' ? $filename : 'document';
    }
}
