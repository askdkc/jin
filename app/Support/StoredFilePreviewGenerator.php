<?php

namespace App\Support;

use App\Models\StoredFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoredFilePreviewGenerator
{
    /** @var list<string> */
    private const OFFICE_EXTENSIONS = [
        'doc',
        'docx',
        'odt',
        'xls',
        'xlsx',
        'xlsm',
        'ods',
        'ppt',
        'pptx',
        'odp',
    ];

    /** @var list<string> */
    public const SUPPORTED_EXTENSIONS = [
        'pdf',
        ...self::OFFICE_EXTENSIONS,
    ];

    public function __construct(private OnlyOfficeThumbnailService $onlyOfficeThumbnailService) {}

    public static function supports(?string $name): bool
    {
        $extension = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return $extension === 'pdf'
            || ((bool) config('onlyoffice.enabled', false)
                && in_array($extension, self::OFFICE_EXTENSIONS, true));
    }

    public static function sourceHash(string $sourcePath): string
    {
        return substr(hash('sha256', $sourcePath), 0, 16);
    }

    public static function webpPath(int $storedFileId, string $sourcePath): string
    {
        return "previews/{$storedFileId}-".self::sourceHash($sourcePath).'.webp';
    }

    public static function cachedPdfPath(int $storedFileId, string $sourcePath): string
    {
        return "previews/{$storedFileId}-".self::sourceHash($sourcePath).'.pdf';
    }

    public static function legacyWebpPath(int $storedFileId): string
    {
        return "previews/{$storedFileId}.webp";
    }

    public static function legacyPdfPath(int $storedFileId): string
    {
        return "previews/{$storedFileId}.pdf";
    }

    /** @return list<string> */
    public static function legacyPaths(int $storedFileId): array
    {
        return [
            self::legacyPdfPath($storedFileId),
            self::legacyWebpPath($storedFileId),
        ];
    }

    /**
     * Remove only the derivatives belonging to one source version.
     *
     * Legacy ID-only paths are included only by callers explicitly cleaning up
     * a replacement or deletion; they are never read as a cache.
     */
    public static function cleanup(
        FilesystemAdapter $disk,
        int $storedFileId,
        string $sourcePath,
        ?string $storedPreviewPath = null,
        bool $includeLegacy = false,
    ): void {
        $paths = [
            self::cachedPdfPath($storedFileId, $sourcePath),
            self::webpPath($storedFileId, $sourcePath),
        ];

        if ($storedPreviewPath !== null) {
            $paths[] = $storedPreviewPath;
        }

        if ($includeLegacy) {
            $paths = [...$paths, ...self::legacyPaths($storedFileId)];
        }

        foreach (array_unique($paths) as $path) {
            try {
                if (! $disk->delete($path)) {
                    Log::warning('Stored file preview cleanup failed.', [
                        'stored_file_id' => $storedFileId,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Stored file preview cleanup failed.', [
                    'stored_file_id' => $storedFileId,
                    'path' => $path,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function generate(StoredFile $storedFile): string
    {
        $disk = Storage::disk($storedFile->disk);
        $extension = strtolower(pathinfo($storedFile->original_name, PATHINFO_EXTENSION));

        $workDir = sys_get_temp_dir().'/stored-file-preview-'.bin2hex(random_bytes(8));
        File::makeDirectory($workDir, 0700, true);

        try {
            if ($extension === 'pdf') {
                $pdfPath = $disk->path($storedFile->path);

                if (! is_file($pdfPath)) {
                    throw new RuntimeException('PDF input is unavailable.');
                }

                $pngPath = $this->renderPdfFirstPage($pdfPath, $workDir);
            } elseif (in_array($extension, self::OFFICE_EXTENSIONS, true)) {
                $pngPath = $workDir.'/office-thumbnail.png';
                $this->onlyOfficeThumbnailService->generate($storedFile, $pngPath);
            } else {
                throw new RuntimeException('Preview input is unsupported.');
            }

            $webpPath = $workDir.'/preview.webp';
            $webpCommand = [
                config('services.imagemagick.path', 'convert'),
                $pngPath,
            ];

            if (in_array($extension, self::OFFICE_EXTENSIONS, true)) {
                $webpCommand = [
                    ...$webpCommand,
                    '-fuzz',
                    '2%',
                    '-trim',
                    '+repage',
                ];
            }

            $webpResult = Process::timeout((int) config('services.imagemagick.timeout', 45))->run([
                ...$webpCommand,
                '-resize',
                '1600x1600>',
                '-strip',
                '-quality',
                '82',
                $webpPath,
            ]);

            if (! $webpResult->successful() || ! is_file($webpPath)) {
                throw new RuntimeException('WebP conversion failed.');
            }

            $previewPath = self::webpPath($storedFile->id, $storedFile->path);
            $contents = file_get_contents($webpPath);

            if ($contents === false || ! $disk->put($previewPath, $contents)) {
                throw new RuntimeException('WebP storage failed.');
            }

            return $previewPath;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function renderPdfFirstPage(string $pdfPath, string $workDir): string
    {
        $pngPrefix = $workDir.'/page-1';
        $pngResult = Process::timeout((int) config('services.poppler.timeout', 45))->run([
            config('services.poppler.path', 'pdftoppm'),
            '-f',
            '1',
            '-l',
            '1',
            '-png',
            '-singlefile',
            $pdfPath,
            $pngPrefix,
        ]);

        $pngPath = $pngPrefix.'.png';

        if (! $pngResult->successful() || ! is_file($pngPath)) {
            throw new RuntimeException('PDF page rendering failed.');
        }

        return $pngPath;
    }
}
