<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\SaveMessageBoardPostReactionRequest as SaveReactionRequest;
use App\Http\Requests\Phase4\ReplaceProjectFileRequest;
use App\Http\Requests\Phase4\StoreProjectFileRequest;
use App\Http\Requests\Phase4\UpdateProjectFileRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\InlineAttachments;
use App\Support\OnlyOfficeDocumentTypeResolver;
use App\Support\Reactions;
use App\Support\StoredFilePreviewDispatcher;
use App\Support\StoredFilePreviewGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProjectFileController extends Controller
{
    private const DELETION_LEASE_SECONDS = 60;

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

    public function show(
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
        OnlyOfficeDocumentTypeResolver $onlyOfficeResolver,
    ): InertiaResponse {
        Gate::authorize('view', $project);
        abort_unless($storedFile->project_id === $project->id, 404);

        $canManageProject = Gate::allows('update', $project);
        $userId = request()->user()->id;
        $onlyOfficeSupported = $onlyOfficeResolver->supports($storedFile);
        $storedFile->load([
            'uploader:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'reactors:id,name',
        ]);

        return Inertia::render('projects/files/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'onlyOffice' => [
                'enabled' => (bool) config('onlyoffice.enabled', false),
                'supported' => $onlyOfficeSupported,
                'configUrl' => $onlyOfficeSupported
                    ? route('projects.files.onlyoffice.config', [$currentTeam, $project, $storedFile], absolute: false)
                    : null,
            ],
            'file' => [
                'id' => $storedFile->id,
                'name' => $storedFile->original_name,
                'mimeType' => $storedFile->mime_type,
                'size' => $storedFile->size,
                'previewStatus' => $storedFile->preview_status,
                'previewImageUrl' => $storedFile->preview_status === 'ready'
                    && $storedFile->preview_path !== null
                    && Storage::disk($storedFile->disk)->exists($storedFile->preview_path)
                    ? route('projects.files.thumbnail', [$currentTeam, $project, $storedFile], absolute: false)
                    : null,
                'uploaderName' => $storedFile->uploader?->name,
                'createdAt' => $storedFile->created_at?->toISOString(),
                'canManage' => $canManageProject || $storedFile->uploaded_by === $userId,
            ],
            'reactions' => Reactions::grouped($storedFile->reactors, $userId),
            'comments' => $storedFile->comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'authorName' => $comment->user?->name,
                'createdAt' => $comment->created_at?->toISOString(),
                'canDelete' => $canManageProject || $comment->user_id === $userId,
            ])->all(),
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function download(Team $currentTeam, Project $project, StoredFile $storedFile): StreamedResponse
    {
        Gate::authorize('view', $project);

        return Storage::disk($storedFile->disk)->download($storedFile->path, $storedFile->original_name);
    }

    public function replace(
        ReplaceProjectFileRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): RedirectResponse {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $expectedOldPath = $storedFile->path;
        $newPath = $file->store("projects/{$project->id}", $storedFile->disk);
        abort_if($newPath === false, 500);

        $disk = Storage::disk($storedFile->disk);

        try {
            $newChecksum = StoredFile::checksumFor($disk, $newPath);
            $newSize = $disk->size($newPath);
            $replacement = DB::transaction(function () use ($storedFile, $expectedOldPath, $newPath, $file, $newChecksum, $newSize): ?array {
                $current = StoredFile::query()
                    ->whereKey($storedFile->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null
                    || $current->path !== $expectedOldPath
                    || $current->preview_status === 'deleting') {
                    return null;
                }

                $oldPreviewPath = $current->preview_path;
                $previewStatus = StoredFilePreviewGenerator::supports($current->original_name)
                    ? 'pending'
                    : null;

                throw_unless($current->update([
                    'path' => $newPath,
                    'mime_type' => $file->getMimeType(),
                    'size' => $newSize,
                    'checksum' => $newChecksum,
                    'preview_path' => null,
                    'preview_status' => $previewStatus,
                ]), RuntimeException::class, 'File replacement failed.');

                return [
                    'id' => $current->id,
                    'disk' => $current->disk,
                    'path' => $expectedOldPath,
                    'preview_path' => $oldPreviewPath,
                    'preview_status' => $previewStatus,
                    'new_path' => $newPath,
                ];
            });
        } catch (Throwable $exception) {
            $disk->delete($newPath);

            throw $exception;
        }

        if ($replacement === null) {
            $disk->delete($newPath);

            abort(409, 'The file changed while it was being replaced.');
        }

        $disk = Storage::disk($replacement['disk']);
        $oldPath = $replacement['path'];
        $oldPreviewPath = $replacement['preview_path'];

        if ($disk->exists($oldPath) && ! $disk->delete($oldPath)) {
            Log::warning('Stored file replacement cleanup failed.', [
                'stored_file_id' => $storedFile->id,
                'path' => $oldPath,
            ]);
        }
        StoredFilePreviewGenerator::cleanup(
            $disk,
            $storedFile->id,
            $oldPath,
            $oldPreviewPath,
            includeLegacy: true,
        );

        if ($replacement['preview_status'] === 'pending') {
            app(StoredFilePreviewDispatcher::class)
                ->dispatchAfterCommit($replacement['id'], $replacement['new_path']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File replaced.')]);

        return back();
    }

    public function preview(
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): Response {
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

    public function thumbnail(Team $currentTeam, Project $project, StoredFile $storedFile): Response
    {
        Gate::authorize('view', $project);
        abort_unless($storedFile->project_id === $project->id, 404);
        abort_unless(
            $storedFile->preview_status === 'ready'
                && $storedFile->preview_path !== null,
            404,
        );

        $disk = Storage::disk($storedFile->disk);
        abort_unless($disk->exists($storedFile->preview_path), 404);

        return $disk->response(
            $storedFile->preview_path,
            basename($storedFile->preview_path),
            [
                'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'inline',
                'Content-Type' => 'image/webp',
                'X-Content-Type-Options' => 'nosniff',
            ],
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

    public function toggleReaction(
        SaveReactionRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($storedFile->project_id === $project->id, 404);

        Reactions::toggle(
            $storedFile->reactors(),
            $request->user()->id,
            (string) $request->validated('reaction'),
        );

        return back();
    }

    public function destroy(Team $currentTeam, Project $project, StoredFile $storedFile): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($storedFile->uploaded_by === request()->user()->id || Gate::allows('update', $project), 403);

        $deletion = DB::transaction(function () use ($storedFile): ?array {
            $current = StoredFile::query()
                ->whereKey($storedFile->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return null;
            }

            $retrying = $current->preview_status === 'deleting';

            if ($retrying
                && $current->updated_at?->isAfter(now()->subSeconds(self::DELETION_LEASE_SECONDS))) {
                return null;
            }

            $deletion = [
                'disk' => $current->disk,
                'path' => $current->path,
                'original_name' => $current->original_name,
                'preview_path' => $retrying ? null : $current->preview_path,
                'preview_status' => $retrying ? null : $current->preview_status,
            ];

            $current->update([
                'preview_path' => null,
                'preview_status' => 'deleting',
            ]);

            return [
                ...$deletion,
                'lease_token' => (string) $current->getRawOriginal('updated_at'),
            ];
        });

        if ($deletion === null) {
            return back();
        }

        $disk = Storage::disk($deletion['disk']);

        try {
            if ($disk->exists($deletion['path']) && ! $disk->delete($deletion['path'])) {
                if (Storage::disk($deletion['disk'])->exists($deletion['path'])) {
                    throw new RuntimeException('File deletion failed.');
                }
            }
        } catch (Throwable $exception) {
            $this->restoreAfterDeletionFailure($storedFile->id, $deletion);

            throw $exception;
        }

        StoredFilePreviewGenerator::cleanup(
            $disk,
            $storedFile->id,
            $deletion['path'],
            $deletion['preview_path'],
            includeLegacy: true,
        );

        $this->finalizeDeletion($storedFile->id, $deletion);

        return back();
    }

    /** @param array{path: string, lease_token: string} $deletion */
    private function finalizeDeletion(int $storedFileId, array $deletion): bool
    {
        return DB::transaction(function () use ($storedFileId, $deletion): bool {
            $current = StoredFile::query()
                ->whereKey($storedFileId)
                ->lockForUpdate()
                ->first();

            if ($current === null
                || $current->path !== $deletion['path']
                || $current->preview_status !== 'deleting'
                || (string) $current->getRawOriginal('updated_at') !== $deletion['lease_token']) {
                return false;
            }

            InlineAttachments::removeFromBody($current);
            InlineAttachments::detachMany((new Comment)->getMorphClass(), $current->comments()->pluck('id'));
            $current->comments()->delete();
            // Bound the morph lookup to this project so the existing project index limits the scan.
            Activity::query()
                ->where('project_id', $current->project_id)
                ->where('subject_type', $current->getMorphClass())
                ->where('subject_id', $current->id)
                ->get()
                ->each(fn (Activity $activity) => $activity->update([
                    'metadata' => [...$activity->metadata, 'subjectDeleted' => true],
                ]));
            $current->delete();

            return true;
        });
    }

    /** @param array{disk: string, path: string, original_name: string, preview_path: string|null, preview_status: string|null, lease_token: string} $deletion */
    private function restoreAfterDeletionFailure(int $storedFileId, array $deletion): void
    {
        DB::transaction(function () use ($storedFileId, $deletion): void {
            $current = StoredFile::query()
                ->whereKey($storedFileId)
                ->where('path', $deletion['path'])
                ->where('preview_status', 'deleting')
                ->where('updated_at', $deletion['lease_token'])
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return;
            }

            $needsRegeneration = StoredFilePreviewGenerator::supports($deletion['original_name'])
                && $deletion['preview_status'] !== 'ready';

            $current->update([
                'preview_path' => $deletion['preview_path'],
                'preview_status' => $needsRegeneration ? 'pending' : $deletion['preview_status'],
            ]);

            if ($needsRegeneration) {
                app(StoredFilePreviewDispatcher::class)
                    ->dispatchAfterCommit($storedFileId, $deletion['path']);
            }
        });
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
