<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase9\MaterialIndexRequest;
use App\Http\Requests\Phase9\MoveMaterialRequest;
use App\Http\Requests\Phase9\SaveExternalLinkRequest;
use App\Http\Requests\Phase9\StoreMaterialFolderRequest;
use App\Models\Activity;
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectMaterialController extends Controller
{
    public function index(MaterialIndexRequest $request, Team $currentTeam, Project $project): Response
    {
        $filters = $request->validated();
        $type = $filters['type'] ?? null;
        $folderId = isset($filters['folder']) ? (int) $filters['folder'] : null;
        $updaterId = isset($filters['updater']) ? (int) $filters['updater'] : null;
        $userId = $request->user()->id;
        $canManageProject = Gate::allows('update', $project);
        $items = collect();

        if ($type === null || $type === 'document') {
            $items = $items->concat($project->documents()
                // The material list does not need the potentially large rich-text body.
                ->select(['id', 'project_id', 'material_folder_id', 'created_by', 'title', 'updated_at'])
                ->with(['creator:id,name', 'materialFolder:id,name'])
                ->when(
                    $folderId !== null,
                    fn ($query) => $query->where('material_folder_id', $folderId),
                    fn ($query) => $query->whereNull('material_folder_id'),
                )
                ->when($updaterId !== null, fn ($query) => $query->where('created_by', $updaterId))
                ->get()
                ->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'type' => 'document',
                    'title' => $document->title,
                    'url' => null,
                    'folderId' => $document->material_folder_id,
                    'folderName' => $document->materialFolder?->name,
                    'updaterId' => $document->created_by,
                    'updaterName' => $document->creator?->name,
                    'updatedAt' => $document->updated_at?->toISOString(),
                    'canDelete' => $canManageProject || $document->created_by === $userId,
                ]));
        }

        if ($type === null || $type === 'file') {
            $items = $items->concat($project->storedFiles()
                ->select(['id', 'project_id', 'material_folder_id', 'uploaded_by', 'original_name', 'updated_at'])
                ->inMaterialLibrary()
                ->with(['uploader:id,name', 'materialFolder:id,name'])
                ->when(
                    $folderId !== null,
                    fn ($query) => $query->where('material_folder_id', $folderId),
                    fn ($query) => $query->whereNull('material_folder_id'),
                )
                ->when($updaterId !== null, fn ($query) => $query->where('uploaded_by', $updaterId))
                ->get()
                ->map(fn (StoredFile $file): array => [
                    'id' => $file->id,
                    'type' => 'file',
                    'title' => $file->original_name,
                    'url' => null,
                    'folderId' => $file->material_folder_id,
                    'folderName' => $file->materialFolder?->name,
                    'updaterId' => $file->uploaded_by,
                    'updaterName' => $file->uploader?->name,
                    'updatedAt' => $file->updated_at?->toISOString(),
                    'canDelete' => $canManageProject || $file->uploaded_by === $userId,
                ]));
        }

        if ($type === null || $type === 'link') {
            $items = $items->concat($project->externalLinks()
                ->select(['id', 'project_id', 'material_folder_id', 'created_by', 'title', 'url', 'updated_at'])
                ->with(['creator:id,name', 'materialFolder:id,name'])
                ->when(
                    $folderId !== null,
                    fn ($query) => $query->where('material_folder_id', $folderId),
                    fn ($query) => $query->whereNull('material_folder_id'),
                )
                ->when($updaterId !== null, fn ($query) => $query->where('created_by', $updaterId))
                ->get()
                ->map(fn (ExternalLink $link): array => [
                    'id' => $link->id,
                    'type' => 'link',
                    'title' => $link->title,
                    'url' => $link->url,
                    'folderId' => $link->material_folder_id,
                    'folderName' => $link->materialFolder?->name,
                    'updaterId' => $link->created_by,
                    'updaterName' => $link->creator?->name,
                    'updatedAt' => $link->updated_at?->toISOString(),
                    'canDelete' => $canManageProject || $link->created_by === $userId,
                ]));
        }

        return Inertia::render('projects/materials/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'items' => $items->sortByDesc('updatedAt')->values(),
            'folders' => $project->materialFolders()
                ->select(['id', 'project_id', 'created_by', 'name', 'position'])
                ->withCount([
                    'documents',
                    'storedFiles' => fn ($query) => $query->inMaterialLibrary(),
                    'externalLinks',
                ])
                ->orderBy('position')
                ->get()
                ->map(fn (MaterialFolder $folder): array => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'itemCount' => (int) $folder->documents_count
                        + (int) $folder->stored_files_count
                        + (int) $folder->external_links_count,
                    // Folder deletion follows the same creator-or-project-manager policy.
                    'canDelete' => $folder->created_by === $userId || $canManageProject,
                ]),
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'filters' => [
                'type' => $type,
                'folder' => $folderId,
                'updater' => $updaterId,
            ],
        ]);
    }

    public function storeFolder(
        StoreMaterialFolderRequest $request,
        Team $currentTeam,
        Project $project,
    ): RedirectResponse {
        $project->materialFolders()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'position' => ((int) $project->materialFolders()->max('position')) + 1,
        ]);

        return to_route('projects.materials.index', [$currentTeam, $project]);
    }

    public function destroyFolder(
        Team $currentTeam,
        Project $project,
        MaterialFolder $materialFolder,
    ): RedirectResponse {
        Gate::authorize('delete', $materialFolder);
        $materialFolder->delete();

        return to_route('projects.materials.index', [$currentTeam, $project]);
    }

    public function storeLink(
        SaveExternalLinkRequest $request,
        Team $currentTeam,
        Project $project,
    ): RedirectResponse {
        $link = $project->externalLinks()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        Activity::record($project, $request->user(), ActivityType::ExternalLinkCreated, $link, $link->title);

        return to_route('projects.materials.index', [$currentTeam, $project]);
    }

    public function destroyLink(
        Team $currentTeam,
        Project $project,
        ExternalLink $externalLink,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless(
            $externalLink->created_by === request()->user()->id || Gate::allows('update', $project),
            403,
        );
        $externalLink->delete();

        return to_route('projects.materials.index', [$currentTeam, $project]);
    }

    public function moveDocument(
        MoveMaterialRequest $request,
        Team $currentTeam,
        Project $project,
        Document $document,
    ): RedirectResponse {
        $this->authorizeMaterialAuthor($project, $document->created_by);
        $document->update($request->validated());

        return back();
    }

    public function moveFile(
        MoveMaterialRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): RedirectResponse {
        $this->authorizeMaterialAuthor($project, $storedFile->uploaded_by);
        $storedFile->update($request->validated());

        return back();
    }

    private function authorizeMaterialAuthor(Project $project, ?int $authorId): void
    {
        abort_unless($authorId === request()->user()->id || Gate::allows('update', $project), 403);
    }
}
