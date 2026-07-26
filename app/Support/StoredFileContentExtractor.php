<?php

namespace App\Support;

use App\Enums\StoredFileContentFailure;
use App\Exceptions\StoredFileContentExtractionException;
use App\Models\StoredFile;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

final class StoredFileContentExtractor
{
    private const COMPOUND_FILE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** @var list<string> */
    private const PROTECTED_OUTPUT_MARKERS = [
        'encrypted document',
        'encrypted documents are not supported',
        'file has not been decrypted',
        'incorrect password',
        'invalid password',
        'password required',
        'password-protected',
        'password protected',
    ];

    public function __construct(
        private StoredFileContentTypeResolver $typeResolver,
    ) {}

    public function extract(
        StoredFile $storedFile,
        string $expectedChecksum,
    ): StoredFileContentExtractionResult {
        $type = $this->typeResolver->resolve($storedFile);

        if ($type === null) {
            return StoredFileContentExtractionResult::failed(
                'none',
                StoredFileContentFailure::UnsupportedFormat,
            );
        }

        $workDirectory = sys_get_temp_dir().'/stored-file-content-'.bin2hex(random_bytes(8));
        File::makeDirectory($workDirectory, 0700, true);

        try {
            try {
                $sourcePath = $this->stageSource(
                    Storage::disk($storedFile->disk),
                    $storedFile->path,
                    $workDirectory,
                    $type['extension'],
                    $expectedChecksum,
                );
            } catch (StoredFileContentExtractionException $exception) {
                if (! $exception->retryable) {
                    return StoredFileContentExtractionResult::failed(
                        $type['extractor'],
                        $exception->failure,
                    );
                }

                throw $exception;
            }

            if ($this->isProtectedDocument($sourcePath, $type['extension'])) {
                return StoredFileContentExtractionResult::protected('markitdown');
            }

            return $this->extractWithMarkItDown(
                $sourcePath,
                $workDirectory,
            );
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    private function isProtectedDocument(string $sourcePath, string $extension): bool
    {
        return $this->isEncryptedOoxml($sourcePath, $extension)
            || ($extension === 'pdf' && $this->isEncryptedPdf($sourcePath));
    }

    private function isEncryptedOoxml(string $sourcePath, string $extension): bool
    {
        if (! in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            return false;
        }

        $source = fopen($sourcePath, 'rb');

        if (! is_resource($source)) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Stored file content source could not be inspected.',
            );
        }

        try {
            $signature = fread($source, strlen(self::COMPOUND_FILE_SIGNATURE));
        } finally {
            fclose($source);
        }

        if ($signature === false) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Stored file content source could not be inspected.',
            );
        }

        return hash_equals(self::COMPOUND_FILE_SIGNATURE, $signature);
    }

    private function isEncryptedPdf(string $sourcePath): bool
    {
        $source = fopen($sourcePath, 'rb');

        if (! is_resource($source)) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Stored file content source could not be inspected.',
            );
        }

        $carry = '';

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new StoredFileContentExtractionException(
                        StoredFileContentFailure::SourceReadFailed,
                        retryable: true,
                        message: 'Stored file content source could not be inspected.',
                    );
                }

                $candidate = $carry.$chunk;

                if (preg_match('/\/Encrypt(?=[\x00\t\n\f\r <>\[\]()\/%])/', $candidate) === 1) {
                    return true;
                }

                $carry = substr($candidate, -16);
            }
        } finally {
            fclose($source);
        }

        return false;
    }

    private function extractWithMarkItDown(
        string $sourcePath,
        string $workDirectory,
    ): StoredFileContentExtractionResult {
        $outputPath = $workDirectory.'/content.md';
        $process = $this->runBoundedProcess([
            (string) config('stored-file-content.markitdown_path', 'markitdown'),
            $sourcePath,
            '-o',
            $outputPath,
        ]);

        if ($process === null) {
            return StoredFileContentExtractionResult::failed(
                'markitdown',
                StoredFileContentFailure::OutputTooLarge,
            );
        }

        if (! $process['result']->successful()) {
            return $this->failedProcessResult(
                $process['output'],
                $process['error_output'],
                'markitdown',
            );
        }

        if (! is_file($outputPath)) {
            return StoredFileContentExtractionResult::failed(
                'markitdown',
                StoredFileContentFailure::ProcessFailed,
            );
        }

        $size = filesize($outputPath);

        if ($size === false) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Extracted content size could not be read.',
            );
        }

        if ($size > $this->maxOutputBytes()) {
            return StoredFileContentExtractionResult::failed(
                'markitdown',
                StoredFileContentFailure::OutputTooLarge,
            );
        }

        $content = file_get_contents($outputPath);

        if ($content === false) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Extracted content could not be read.',
            );
        }

        return $this->readyResult('markitdown', $content);
    }

    private function failedProcessResult(
        string $output,
        string $errorOutput,
        string $extractor,
    ): StoredFileContentExtractionResult {
        return $this->isProtected($output, $errorOutput)
            ? StoredFileContentExtractionResult::protected($extractor)
            : StoredFileContentExtractionResult::failed(
                $extractor,
                StoredFileContentFailure::ProcessFailed,
            );
    }

    private function readyResult(
        string $extractor,
        string $content,
    ): StoredFileContentExtractionResult {
        if (strlen($content) > $this->maxOutputBytes()) {
            return StoredFileContentExtractionResult::failed(
                $extractor,
                StoredFileContentFailure::OutputTooLarge,
            );
        }

        $content = $this->normalize($content);
        $isTruncated = strlen($content) > $this->maxIndexedBytes();

        if ($isTruncated) {
            $content = rtrim(
                mb_strcut($content, 0, $this->maxIndexedBytes(), 'UTF-8'),
            );
        }

        return StoredFileContentExtractionResult::ready(
            $extractor,
            $content === '' ? null : $content,
            $isTruncated,
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{result: ProcessResult, output: string, error_output: string}|null
     */
    private function runBoundedProcess(array $command): ?array
    {
        $output = '';
        $errorOutput = '';

        try {
            $process = Process::timeout(
                (int) config('stored-file-content.process_timeout', 120),
            )->start($command);
        } catch (Throwable $exception) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::ProcessFailed,
                retryable: true,
                message: 'Stored file content extraction process could not start.',
                previous: $exception,
            );
        }

        $outputTooLarge = false;

        try {
            $result = $process->waitUntil(function (
                string $type,
                string $buffer,
            ) use (&$output, &$errorOutput, &$outputTooLarge): bool {
                if (strlen($output) + strlen($errorOutput) + strlen($buffer) > $this->maxOutputBytes()) {
                    $outputTooLarge = true;

                    return true;
                }

                if ($type === SymfonyProcess::OUT) {
                    $output .= $buffer;
                } else {
                    $errorOutput .= $buffer;
                }

                return false;
            });

            if ($outputTooLarge) {
                $process->signal(15);

                return null;
            }

            return [
                'result' => $result,
                'output' => $output,
                'error_output' => $errorOutput,
            ];
        } catch (ProcessTimedOutException $exception) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::ProcessTimeout,
                retryable: true,
                message: 'Stored file content extraction timed out.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::ProcessFailed,
                retryable: true,
                message: 'Stored file content extraction process failed.',
                previous: $exception,
            );
        }
    }

    private function stageSource(
        FilesystemAdapter $disk,
        string $storagePath,
        string $workDirectory,
        string $extension,
        string $expectedChecksum,
    ): string {
        try {
            $source = $disk->readStream($storagePath);
        } catch (Throwable $exception) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceMissing,
                retryable: true,
                message: 'Stored file content source is unavailable.',
                previous: $exception,
            );
        }

        if (! is_resource($source)) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceMissing,
                retryable: true,
                message: 'Stored file content source is unavailable.',
            );
        }

        $stagedPath = $workDirectory.'/source.'.$extension;
        $destination = fopen($stagedPath, 'wb');

        if (! is_resource($destination)) {
            fclose($source);

            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::SourceReadFailed,
                retryable: true,
                message: 'Stored file content staging failed.',
            );
        }

        $bytes = 0;
        $hash = hash_init('sha256');

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new StoredFileContentExtractionException(
                        StoredFileContentFailure::SourceReadFailed,
                        retryable: true,
                        message: 'Stored file content source could not be read.',
                    );
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $this->maxSourceBytes()) {
                    throw new StoredFileContentExtractionException(
                        StoredFileContentFailure::SourceTooLarge,
                        retryable: false,
                        message: 'Stored file content source exceeds the extraction limit.',
                    );
                }

                hash_update($hash, $chunk);

                if (fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new StoredFileContentExtractionException(
                        StoredFileContentFailure::SourceReadFailed,
                        retryable: true,
                        message: 'Stored file content staging failed.',
                    );
                }
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        chmod($stagedPath, 0600);

        if (! hash_equals($expectedChecksum, hash_final($hash))) {
            throw new StoredFileContentExtractionException(
                StoredFileContentFailure::ChecksumMismatch,
                retryable: false,
                message: 'Stored file content checksum changed during extraction.',
            );
        }

        return $stagedPath;
    }

    private function normalize(string $content): string
    {
        $content = mb_scrub($content, 'UTF-8');
        $content = str_replace("\0", '', $content);
        $content = preg_replace(
            '/[\x{0001}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u',
            '',
            $content,
        ) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $content) ?? '');
    }

    private function isProtected(string $output, string $errorOutput): bool
    {
        $output = mb_strtolower($errorOutput.' '.$output);

        foreach (self::PROTECTED_OUTPUT_MARKERS as $marker) {
            if (str_contains($output, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function maxSourceBytes(): int
    {
        return (int) config('stored-file-content.max_source_bytes', 50 * 1024 * 1024);
    }

    private function maxOutputBytes(): int
    {
        return (int) config('stored-file-content.max_output_bytes', 16 * 1024 * 1024);
    }

    private function maxIndexedBytes(): int
    {
        return (int) config('stored-file-content.max_indexed_bytes', 2 * 1024 * 1024);
    }
}
