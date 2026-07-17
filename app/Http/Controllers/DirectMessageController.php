<?php

namespace App\Http\Controllers;

use App\Events\DirectMessageCreated;
use App\Http\Requests\Pings\StoreDirectMessageRequest;
use App\Models\DirectMessage;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DirectMessageController extends Controller
{
    public function store(StoreDirectMessageRequest $request, Team $currentTeam): RedirectResponse
    {
        $message = $currentTeam->directMessages()->create([
            'sender_id' => $request->user()->id,
            'recipient_id' => $request->integer('recipient_id'),
            'body' => $request->validated('body'),
        ]);

        DirectMessageCreated::dispatch($message);

        return back();
    }

    public function markRead(Request $request, Team $currentTeam): RedirectResponse
    {
        DirectMessage::query()
            ->where('team_id', $currentTeam->id)
            ->where('recipient_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
