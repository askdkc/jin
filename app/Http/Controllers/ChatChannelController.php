<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StoreChatChannelRequest;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ChatChannelController extends Controller
{
    /**
     * Create a project chat channel.
     */
    public function store(StoreChatChannelRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $channel = $project->chatChannels()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel created.')]);

        return to_route('projects.chat.show', [
            'current_team' => $currentTeam,
            'project' => $project,
            'channel' => $channel->id,
        ]);
    }
}
