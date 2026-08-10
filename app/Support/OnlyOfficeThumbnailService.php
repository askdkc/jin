<?php

namespace App\Support;

use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ValueError;

final class OnlyOfficeThumbnailService
{
    public function __construct(
        private OnlyOfficeConfigService $configService,
        private OnlyOfficeTokenService $tokenService,
    ) {}

    public function generate(StoredFile $storedFile, string $destinationPath): void
    {
        if (! (bool) config('onlyoffice.enabled', false) || ! $this->configService->isConfigured()) {
            throw new RuntimeException('OnlyOffice thumbnail conversion is not configured.');
        }

        if ($storedFile->size > (int) config('onlyoffice.max_file_size')) {
            throw new RuntimeException('Office document exceeds the conversion size limit.');
        }

        $viewer = $this->viewerFor($storedFile);
        $document = $this->configService->make($storedFile, $viewer)['document'] ?? null;

        if (! is_array($document)) {
            throw new RuntimeException('OnlyOffice conversion document is unavailable.');
        }

        $key = (string) ($document['key'] ?? '');
        $request = [
            'async' => false,
            'filetype' => (string) ($document['fileType'] ?? ''),
            'key' => $key,
            'outputtype' => 'png',
            'thumbnail' => [
                'aspect' => 1,
                'first' => true,
                'height' => 1600,
                'width' => 1600,
            ],
            'title' => (string) ($document['title'] ?? ''),
            'url' => (string) ($document['url'] ?? ''),
        ];
        $request['token'] = $this->tokenService->encode($request);
        $documentServerUrl = rtrim((string) config('onlyoffice.document_server_url'), '/');
        $connectTimeout = (int) config('onlyoffice.healthcheck_timeout', 3);
        $conversionResponse = Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout($connectTimeout)
            ->timeout(120)
            ->post($documentServerUrl.'/converter?shardkey='.rawurlencode($key), $request);

        if (! $conversionResponse->successful()) {
            throw new RuntimeException("OnlyOffice thumbnail conversion returned HTTP {$conversionResponse->status()}.");
        }

        $conversion = $conversionResponse->json();

        if (! is_array($conversion)) {
            throw new RuntimeException('OnlyOffice thumbnail conversion returned malformed JSON.');
        }

        $errorCode = $conversion['error'] ?? null;

        if (is_numeric($errorCode) && (int) $errorCode !== 0) {
            throw new RuntimeException('OnlyOffice thumbnail conversion failed with code '.(int) $errorCode.'.');
        }

        $thumbnailUrl = $conversion['fileUrl'] ?? null;

        if (($conversion['endConvert'] ?? false) !== true
            || ($conversion['fileType'] ?? null) !== 'png'
            || ! is_string($thumbnailUrl)
            || ! $this->isTrustedDocumentServerUrl($thumbnailUrl, $documentServerUrl)) {
            throw new RuntimeException('OnlyOffice thumbnail conversion returned an invalid result.');
        }

        File::delete($destinationPath);
        $downloadResponse = Http::accept('image/png')
            ->withoutRedirecting()
            ->connectTimeout($connectTimeout)
            ->timeout(30)
            ->sink($destinationPath)
            ->get($thumbnailUrl);

        if (! $downloadResponse->successful() || ! File::isFile($destinationPath)) {
            File::delete($destinationPath);

            throw new RuntimeException('OnlyOffice thumbnail download failed.');
        }

        $maximumBytes = (int) config('onlyoffice.document_server_max_download_bytes');

        if ($maximumBytes < 1 || File::size($destinationPath) > $maximumBytes) {
            File::delete($destinationPath);

            throw new RuntimeException('OnlyOffice thumbnail exceeds the download size limit.');
        }

        if (! $this->hasPngSignature($destinationPath)) {
            File::delete($destinationPath);

            throw new RuntimeException('OnlyOffice thumbnail is not a PNG image.');
        }
    }

    private function viewerFor(StoredFile $storedFile): User
    {
        $project = $storedFile->project;
        $viewer = $storedFile->uploaded_by !== null
            ? $project->members()->whereKey($storedFile->uploaded_by)->first()
            : null;
        $viewer ??= $project->members()->first();

        if (! $viewer instanceof User) {
            throw new RuntimeException('Office document has no authorized preview viewer.');
        }

        return $viewer;
    }

    private function isTrustedDocumentServerUrl(string $url, string $documentServerUrl): bool
    {
        try {
            $candidate = parse_url($url);
            $server = parse_url($documentServerUrl);
        } catch (ValueError) {
            return false;
        }

        if (! is_array($candidate) || ! is_array($server)
            || isset($candidate['user']) || isset($candidate['pass'])) {
            return false;
        }

        return Str::lower((string) ($candidate['scheme'] ?? '')) === Str::lower((string) ($server['scheme'] ?? ''))
            && Str::lower((string) ($candidate['host'] ?? '')) === Str::lower((string) ($server['host'] ?? ''))
            && $this->normalizedPort($candidate) === $this->normalizedPort($server);
    }

    private function hasPngSignature(string $path): bool
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            return fread($stream, 8) === "\x89PNG\r\n\x1a\n";
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $url */
    private function normalizedPort(array $url): int
    {
        if (isset($url['port'])) {
            return (int) $url['port'];
        }

        return Str::lower((string) ($url['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
