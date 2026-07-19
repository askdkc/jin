<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase4\SaveDocumentRequest;
use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Models\Team;
use App\Support\InlineAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectDocumentController extends Controller
{
    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $canManageProject = Gate::allows('update', $project);
        $documents = $project->documents()
            ->with(['creator:id,name', 'materialFolder:id,name'])
            ->latest('updated_at')
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'body' => $document->body,
                'creatorName' => $document->creator?->name,
                'folderId' => $document->material_folder_id,
                'folderName' => $document->materialFolder?->name,
                'updatedAt' => $document->updated_at?->toISOString(),
                'canEdit' => $canManageProject || $document->created_by === request()->user()->id,
                'publicUrl' => $document->published_at && $document->public_token
                    ? route('public-documents.show', $document->public_token)
                    : null,
            ]);

        return Inertia::render('projects/documents/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'documents' => $documents,
            'folders' => $project->materialFolders()->orderBy('position')->get(['id', 'name']),
        ]);
    }

    public function store(SaveDocumentRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        DB::transaction(function () use ($request, $project): void {
            $document = $project->documents()->create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
            ]);

            InlineAttachments::syncFromBody(
                $document,
                $project,
                $request->user()->id,
                $document->body,
            );

            Activity::record(
                $project,
                $request->user(),
                ActivityType::DocumentCreated,
                $document,
                $document->title,
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document created.')]);

        return to_route('projects.documents.index', [$currentTeam, $project]);
    }

    public function update(
        SaveDocumentRequest $request,
        Team $currentTeam,
        Project $project,
        Document $document,
    ): RedirectResponse {
        $document->update($request->validated());

        InlineAttachments::syncFromBody(
            $document,
            $project,
            $request->user()->id,
            $document->body,
        );

        return to_route('projects.documents.index', [$currentTeam, $project]);
    }

    public function destroy(Team $currentTeam, Project $project, Document $document): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($document->created_by === request()->user()->id || Gate::allows('update', $project), 403);

        $document->delete();

        return to_route('projects.documents.index', [$currentTeam, $project]);
    }

    public function togglePublicLink(
        Team $currentTeam,
        Project $project,
        Document $document,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($document->created_by === request()->user()->id || Gate::allows('update', $project), 403);

        $document->update($document->published_at
            ? ['public_token' => null, 'published_at' => null]
            : ['public_token' => Str::random(48), 'published_at' => now()]);

        return to_route('projects.documents.index', [$currentTeam, $project]);
    }
}
