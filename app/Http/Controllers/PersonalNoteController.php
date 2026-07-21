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
            ->select(['id', 'title', 'body', 'updated_at'])
            ->latest('updated_at')
            ->latest('id')
            // Each body can be 50 KB; bound both the query result and Inertia payload.
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PersonalNote $note): array => [
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

        // The notes page needs page 1; the global quick-note dock must keep its current page.
        return $request->boolean('return_to_notes_index')
            ? to_route('notes.index', $currentTeam)
            : back();
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

        // An updated note moves to the first page because the list is ordered by updated_at.
        return to_route('notes.index', $currentTeam);
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

        return to_route('notes.index', $currentTeam);
    }
}
