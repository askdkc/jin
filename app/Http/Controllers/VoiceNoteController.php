<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoiceNotes\StoreVoiceNoteRequest;
use App\Models\Team;
use App\Models\VoiceNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class VoiceNoteController extends Controller
{
    public function store(StoreVoiceNoteRequest $request, Team $currentTeam): JsonResponse
    {
        $audio = $request->file('audio');
        abort_unless($audio instanceof UploadedFile, 422);

        $extension = strtolower($audio->getClientOriginalExtension()) ?: 'webm';
        $mimeType = match ($extension) {
            'ogg' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'wav' => 'audio/wav',
            default => 'audio/webm',
        };
        $path = $audio->storeAs(
            "voice-notes/{$currentTeam->id}",
            str()->uuid().'.'.$extension,
            'local',
        );
        throw_if($path === false, RuntimeException::class, 'Voice note storage failed.');

        $voiceNote = VoiceNote::query()->create([
            'team_id' => $currentTeam->id,
            'user_id' => $request->user()->id,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $audio->getSize(),
        ]);

        return response()->json([
            'id' => $voiceNote->id,
            'url' => route('voice-notes.show', [$currentTeam, $voiceNote]),
        ], 201);
    }

    public function show(Team $currentTeam, VoiceNote $voiceNote): Response
    {
        abort_unless($voiceNote->team_id === $currentTeam->id, 404);

        return Storage::disk($voiceNote->disk)->response(
            $voiceNote->path,
            "voice-note-{$voiceNote->id}",
            [
                'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => 'inline',
                'Content-Type' => $voiceNote->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
