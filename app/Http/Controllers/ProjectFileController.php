<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase4\StoreProjectFileRequest;
use App\Http\Requests\Phase4\UpdateProjectFileRequest;
use App\Models\Activity;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProjectFileController extends Controller
{
    public function index(Team $currentTeam, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        $canManageProject = Gate::allows('update', $project);
        $files = $project->storedFiles()
            ->with(['uploader:id,name', 'materialFolder:id,name'])
            ->latest()
            ->get()
            ->map(fn (StoredFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mimeType' => $file->mime_type,
                'size' => $file->size,
                'uploaderName' => $file->uploader?->name,
                'folderId' => $file->material_folder_id,
                'folderName' => $file->materialFolder?->name,
                'createdAt' => $file->created_at?->toISOString(),
                'canDelete' => $canManageProject || $file->uploaded_by === request()->user()->id,
            ]);

        return Inertia::render('projects/files/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'files' => $files,
            'folders' => $project->materialFolders()->orderBy('position')->get(['id', 'name']),
        ]);
    }

    public function store(StoreProjectFileRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $files = $request->file('files');

        if (! is_array($files)) {
            $file = $request->file('file');
            abort_unless($file instanceof UploadedFile, 422);
            $files = [$file];
        }

        /** @var list<array{file: UploadedFile, path: string}> $uploads */
        $uploads = [];
        $folderName = $request->string('folder_name')->trim()->toString();
        $materialFolderId = $request->integer('material_folder_id') ?: null;

        try {
            foreach ($files as $file) {
                $path = $file->store("projects/{$project->id}", 'local');
                throw_if($path === false, RuntimeException::class, 'File storage failed.');
                $uploads[] = ['file' => $file, 'path' => $path];
            }

            DB::transaction(function () use ($request, $project, $uploads, $folderName, $materialFolderId): void {
                $destinationFolderId = $materialFolderId;

                if ($folderName !== '') {
                    $destinationFolderId = $project->materialFolders()->firstOrCreate(
                        ['name' => $folderName],
                        [
                            'created_by' => $request->user()->id,
                            'position' => ((int) $project->materialFolders()->max('position')) + 1,
                        ],
                    )->id;
                }

                foreach ($uploads as ['file' => $file, 'path' => $path]) {
                    $storedFile = $project->storedFiles()->create([
                        'material_folder_id' => $destinationFolderId,
                        'uploaded_by' => $request->user()->id,
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);

                    Activity::record(
                        $project,
                        $request->user(),
                        ActivityType::FileUploaded,
                        $storedFile,
                        $storedFile->original_name,
                    );
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(Arr::pluck($uploads, 'path'));

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File uploaded.')]);

        return back();
    }

    public function download(Team $currentTeam, Project $project, StoredFile $storedFile): StreamedResponse
    {
        Gate::authorize('view', $project);

        return Storage::disk($storedFile->disk)->download($storedFile->path, $storedFile->original_name);
    }

    public function preview(Team $currentTeam, Project $project, StoredFile $storedFile): Response
    {
        Gate::authorize('view', $project);

        $disk = Storage::disk($storedFile->disk);
        abort_unless($disk->exists($storedFile->path), 404);

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $extension = strtolower(pathinfo($storedFile->original_name, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return response($this->csvAsUtf8($disk->get($storedFile->path)), 200, [
                ...$headers,
                'Content-Disposition' => 'inline',
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
            $pdf = $this->officePreviewPdf($disk, $storedFile);

            if ($pdf !== null) {
                return response($pdf, 200, [
                    ...$headers,
                    'Content-Disposition' => 'inline',
                    'Content-Type' => 'application/pdf',
                ]);
            }
        }

        if ($storedFile->mime_type !== null) {
            $headers['Content-Type'] = $storedFile->mime_type;
        }

        return $disk->response(
            $storedFile->path,
            $storedFile->original_name,
            $headers,
            'inline',
        );
    }

    public function update(
        UpdateProjectFileRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): RedirectResponse {
        $storedFile->update([
            'original_name' => $request->validated('original_name'),
        ]);

        return back();
    }

    public function destroy(Team $currentTeam, Project $project, StoredFile $storedFile): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($storedFile->uploaded_by === request()->user()->id || Gate::allows('update', $project), 403);

        $disk = Storage::disk($storedFile->disk);
        abort_if($disk->exists($storedFile->path) && ! $disk->delete($storedFile->path), 500);
        $disk->delete("previews/{$storedFile->id}.pdf");
        $storedFile->delete();

        return back();
    }

    /**
     * PDF rendered from an Office file via LibreOffice, cached on the same disk.
     * Returns null when conversion is unavailable so callers can fall back to
     * streaming the original file.
     */
    private function officePreviewPdf(FilesystemAdapter $disk, StoredFile $storedFile): ?string
    {
        $cachePath = "previews/{$storedFile->id}.pdf";

        if ($disk->exists($cachePath)) {
            return $disk->get($cachePath);
        }

        $workDir = sys_get_temp_dir().'/office-preview-'.uniqid();

        try {
            $result = Process::timeout(120)->run([
                config('services.libreoffice.path'),
                '--headless',
                // Parallel soffice runs deadlock on a shared user profile.
                '-env:UserInstallation=file://'.$workDir.'/profile',
                '--convert-to', 'pdf',
                '--outdir', $workDir,
                $disk->path($storedFile->path),
            ]);

            $converted = $workDir.'/'.pathinfo($storedFile->path, PATHINFO_FILENAME).'.pdf';

            if (! $result->successful() || ! is_file($converted)) {
                Log::warning('Office preview conversion failed.', [
                    'stored_file_id' => $storedFile->id,
                    'output' => $result->errorOutput() ?: $result->output(),
                ]);

                return null;
            }

            $disk->put($cachePath, (string) file_get_contents($converted));

            return $disk->get($cachePath);
        } catch (Throwable $exception) {
            Log::warning('Office preview conversion failed.', [
                'stored_file_id' => $storedFile->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function csvAsUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $encoding = mb_detect_encoding(
            $contents,
            ['SJIS-win', 'EUC-JP', 'ISO-2022-JP'],
            true,
        );

        $converted = mb_convert_encoding($contents, 'UTF-8', $encoding ?: 'SJIS-win');

        if ($converted === false) {
            throw new RuntimeException('CSV encoding conversion failed.');
        }

        return $converted;
    }
}
