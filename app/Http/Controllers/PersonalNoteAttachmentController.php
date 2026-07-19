<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonalNotes\StorePersonalNoteAttachmentRequest;
use App\Models\PersonalNoteAttachment;
use App\Models\Team;
use App\Support\InlineAttachmentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PersonalNoteAttachmentController extends Controller
{
    public function upload(StorePersonalNoteAttachmentRequest $request, Team $currentTeam): JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $path = $file->store("personal-notes/{$currentTeam->id}/attachments", 'local');
        throw_if($path === false, RuntimeException::class, 'File storage failed.');

        try {
            $attachment = PersonalNoteAttachment::create([
                'team_id' => $currentTeam->id,
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

        $url = route(
            'personal-note-attachments.preview',
            [$currentTeam, $attachment],
            absolute: false,
        );

        return response()->json([
            'url' => $url,
            'previewUrl' => $url,
            'name' => $attachment->original_name,
            'mimeType' => $attachment->mime_type,
            'inline' => InlineAttachmentPolicy::isInline($attachment->mime_type),
        ], 201);
    }

    public function preview(
        Request $request,
        Team $currentTeam,
        PersonalNoteAttachment $personalNoteAttachment,
    ): Response {
        abort_unless(
            $currentTeam->id === $personalNoteAttachment->team_id
                && $request->user()->id === $personalNoteAttachment->uploaded_by,
            403,
        );

        $disk = Storage::disk($personalNoteAttachment->disk);
        abort_unless($disk->exists($personalNoteAttachment->path), 404);

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($personalNoteAttachment->mime_type !== null) {
            $headers['Content-Type'] = $personalNoteAttachment->mime_type;
        }

        $disposition = InlineAttachmentPolicy::isInline($personalNoteAttachment->mime_type)
            ? 'inline'
            : 'attachment';

        return $disk->response(
            $personalNoteAttachment->path,
            $personalNoteAttachment->original_name,
            $headers,
            $disposition,
        );
    }
}
