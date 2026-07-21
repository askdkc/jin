<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase4\StoreProjectFileRequest;
use App\Models\Activity;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Support\InlineAttachmentPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class AttachmentController extends Controller
{
    public function uploadInline(StoreProjectFileRequest $request, Team $currentTeam, Project $project): JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $path = $file->store("projects/{$project->id}/attachments", 'local');
        throw_if($path === false, RuntimeException::class, 'File storage failed.');

        try {
            $storedFile = $project->storedFiles()->create([
                'uploaded_by' => $request->user()->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return response()->json([
            'id' => $storedFile->id,
            'name' => $storedFile->original_name,
            'mimeType' => $storedFile->mime_type,
            'previewUrl' => route('projects.files.preview', [$currentTeam, $project, $storedFile], absolute: false),
            'inline' => InlineAttachmentPolicy::isInline($storedFile->mime_type),
        ], 201);
    }

    public function storeMessage(
        StoreProjectFileRequest $request,
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        return $this->storeAttachment(
            $request,
            $project,
            $messageBoardPost,
            'projects.messages.show',
            [$currentTeam, $project, $messageBoardPost],
        );
    }

    public function storeTodo(
        StoreProjectFileRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        return $this->storeAttachment(
            $request,
            $project,
            $todo,
            'projects.todos.index',
            [$currentTeam, $project],
        );
    }

    /** @param array<int, Model> $routeParameters */
    private function storeAttachment(
        StoreProjectFileRequest $request,
        Project $project,
        Model $attachable,
        string $redirectRoute,
        array $routeParameters,
    ): RedirectResponse {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $path = $file->store("projects/{$project->id}/attachments", 'local');
        throw_if($path === false, RuntimeException::class, 'File storage failed.');

        try {
            DB::transaction(function () use ($attachable, $file, $path, $project, $request): void {
                $storedFile = $project->storedFiles()->create([
                    'uploaded_by' => $request->user()->id,
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
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
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File attached.')]);

        return back(fallback: route($redirectRoute, $routeParameters));
    }
}
