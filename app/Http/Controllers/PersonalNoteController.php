<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonalNotes\SavePersonalNoteRequest;
use App\Models\PersonalNote;
use App\Models\Team;
use App\Support\PersonalNoteAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PersonalNoteController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $notes = $request->user()->personalNotes()
            ->where('team_id', $currentTeam->id)
            ->latest('updated_at')
            ->get()
            ->map(fn (PersonalNote $note): array => [
                'id' => $note->id,
                'title' => $note->title,
                'body' => $note->body,
                'updatedAt' => $note->updated_at?->toISOString(),
            ]);

        return Inertia::render('notes/Index', ['notes' => $notes]);
    }

    public function store(
        SavePersonalNoteRequest $request,
        Team $currentTeam,
        PersonalNoteAttachments $personalNoteAttachments,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $currentTeam, $personalNoteAttachments): void {
            $note = $request->user()->personalNotes()->create([
                ...$request->validated(),
                'team_id' => $currentTeam->id,
            ]);

            $personalNoteAttachments->syncFromBody($note, $request->user()->id, $note->body);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note saved.')]);

        return back();
    }

    public function update(
        SavePersonalNoteRequest $request,
        Team $currentTeam,
        PersonalNote $personalNote,
        PersonalNoteAttachments $personalNoteAttachments,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $personalNote, $personalNoteAttachments): void {
            $personalNote->update($request->validated());
            $personalNoteAttachments->syncFromBody($personalNote, $request->user()->id, $personalNote->body);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note updated.')]);

        return back();
    }

    public function destroy(
        Request $request,
        Team $currentTeam,
        PersonalNote $personalNote,
        PersonalNoteAttachments $personalNoteAttachments,
    ): RedirectResponse {
        abort_unless($personalNote->team_id === $currentTeam->id && $personalNote->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($personalNote, $personalNoteAttachments): void {
            $personalNoteAttachments->deleteFor($personalNote);
            $personalNote->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note deleted.')]);

        return back();
    }
}
