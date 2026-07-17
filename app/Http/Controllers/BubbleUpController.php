<?php

namespace App\Http\Controllers;

use App\Http\Requests\BubbleUps\StoreBubbleUpRequest;
use App\Models\BubbleUp;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BubbleUpController extends Controller
{
    public function store(StoreBubbleUpRequest $request, Team $currentTeam): RedirectResponse
    {
        $request->user()->bubbleUps()->create([
            ...$request->validated(),
            'team_id' => $currentTeam->id,
        ]);

        return back();
    }

    public function complete(Request $request, Team $currentTeam, BubbleUp $bubbleUp): RedirectResponse
    {
        abort_unless($bubbleUp->team_id === $currentTeam->id && $bubbleUp->user_id === $request->user()->id, 404);

        $bubbleUp->update(['completed_at' => now()]);

        return back();
    }
}
