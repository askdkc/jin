<?php

namespace App\Support;

use App\Models\StoredFile;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OnlyOfficeConfigService
{
    public function __construct(
        private OnlyOfficeDocumentTypeResolver $documentTypeResolver,
        private OnlyOfficeDocumentVersion $documentVersion,
        private OnlyOfficeTokenService $tokenService,
    ) {}

    public function isConfigured(): bool
    {
        return $this->isHttpBaseUrl(config('onlyoffice.document_server_url'))
            && $this->isHttpBaseUrl(config('onlyoffice.public_url'))
            && $this->isHttpBaseUrl(config('onlyoffice.internal_url'))
            && strlen(trim((string) config('onlyoffice.jwt_secret', ''))) >= 32
            && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', (string) config('onlyoffice.instance_id')) === 1
            && (int) config('onlyoffice.download_ttl') > 0
            && (int) config('onlyoffice.healthcheck_timeout') > 0
            && (int) config('onlyoffice.max_file_size') > 0;
    }

    public function isDocumentServerAvailable(): bool
    {
        try {
            $timeout = (int) config('onlyoffice.healthcheck_timeout', 3);
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->get($this->baseUrl('document_server_url').'/healthcheck');

            return $response->successful()
                && in_array(Str::lower(trim($response->body())), ['true', 'ok'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function make(StoredFile $storedFile, User $viewer): array
    {
        $resolved = $this->documentTypeResolver->resolve($storedFile);
        throw_if($resolved === null, RuntimeException::class, 'Unsupported OnlyOffice document.');
        $version = $this->documentVersion->key($storedFile);
        $issuedAt = now()->timestamp;
        $expiresAt = now()->addSeconds($this->downloadTtl());
        [$editorLanguage, $editorRegion] = $this->editorLocale();

        $config = [
            'type' => 'embedded',
            'documentType' => $resolved['documentType'],
            'height' => '100%',
            'width' => '100%',
            'document' => [
                'fileType' => $resolved['fileType'],
                'key' => $version,
                'title' => $this->safeTitle($storedFile->original_name, $resolved['fileType']),
                'url' => $this->downloadUrl($storedFile, $viewer, $version, $expiresAt),
                'permissions' => [
                    'chat' => false,
                    'comment' => false,
                    'copy' => (bool) config('onlyoffice.allow_copy', true),
                    'download' => (bool) config('onlyoffice.allow_download', false),
                    'edit' => false,
                    'fillForms' => false,
                    'modifyContentControl' => false,
                    'modifyFilter' => false,
                    'print' => (bool) config('onlyoffice.allow_print', false),
                    'protect' => false,
                    'review' => false,
                ],
            ],
            'editorConfig' => [
                'mode' => 'view',
                'lang' => $editorLanguage,
                'region' => $editorRegion,
                'coEditing' => [
                    'mode' => 'strict',
                    'change' => false,
                ],
            ],
        ];
        $payload = [
            ...$config,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiresAt->timestamp,
        ];

        return [...$config, 'token' => $this->tokenService->encode($payload)];
    }

    /** @return array{string, string} */
    private function editorLocale(): array
    {
        return match (app()->getLocale()) {
            'ja' => ['ja', 'ja-JP'],
            'fr' => ['fr', 'fr-FR'],
            'de' => ['de', 'de-DE'],
            'zh_CN' => ['zh', 'zh-CN'],
            'zh_TW' => ['zh-TW', 'zh-TW'],
            'es' => ['es', 'es-ES'],
            'pt_BR' => ['pt', 'pt-BR'],
            'pt_PT' => ['pt-PT', 'pt-PT'],
            'ko' => ['ko', 'ko-KR'],
            default => ['en', 'en-US'],
        };
    }

    private function downloadUrl(
        StoredFile $storedFile,
        User $viewer,
        string $version,
        DateTimeInterface $expiresAt,
    ): string {
        $relativeUrl = URL::temporarySignedRoute(
            'files.onlyoffice.internal-download',
            $expiresAt,
            [
                'file' => $storedFile->getKey(),
                'viewer' => $viewer->getKey(),
                'version' => $version,
            ],
            absolute: false,
        );

        return $this->baseUrl('internal_url').'/'.ltrim($relativeUrl, '/');
    }

    private function downloadTtl(): int
    {
        return min((int) config('onlyoffice.download_ttl', 300), 3600);
    }

    private function safeTitle(?string $originalName, string $fileType): string
    {
        $title = basename(str_replace('\\', '/', (string) $originalName));
        $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '';
        $title = trim($title, " .\t\n\r\0\x0B");

        return Str::limit($title !== '' ? $title : "document.{$fileType}", 128, '');
    }

    private function baseUrl(string $key): string
    {
        return rtrim((string) config("onlyoffice.{$key}"), '/');
    }

    private function isHttpBaseUrl(mixed $url): bool
    {
        if (! is_string($url) || trim($url) !== $url || $url === '') {
            return false;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return false;
        }

        return is_array($parts)
            && in_array(Str::lower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts);
    }
}
