<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\DestroyChatChannelRequest;
use App\Http\Requests\Chat\StoreChatChannelRequest;
use App\Http\Requests\Chat\UpdateChatChannelRequest;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\Team;
use App\Support\InlineAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /**
     * Rename a project chat channel.
     */
    public function update(
        UpdateChatChannelRequest $request,
        Team $currentTeam,
        Project $project,
        ChatChannel $chatChannel,
    ): RedirectResponse {
        $chatChannel->update([
            'name' => $request->string('name')->toString(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel renamed.')]);

        return to_route('projects.chat.show', [
            'current_team' => $currentTeam,
            'project' => $project,
            'channel' => $request->integer('selected_channel_id'),
        ]);
    }

    /**
     * Delete a project chat channel.
     */
    public function destroy(
        DestroyChatChannelRequest $request,
        Team $currentTeam,
        Project $project,
        ChatChannel $chatChannel,
    ): RedirectResponse {
        $nextChannelId = DB::transaction(function () use ($request, $project, $chatChannel): int {
            $channels = $project->chatChannels()
                ->lockForUpdate()
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            if ($channels->count() <= 1) {
                throw ValidationException::withMessages([
                    'channel' => __('At least one channel is required.'),
                ]);
            }

            $selectedChannelId = $request->integer('selected_channel_id');
            $nextChannel = $channels->first(
                fn (ChatChannel $channel): bool => $channel->id !== $chatChannel->id
                    && $channel->id === $selectedChannelId,
            ) ?? $channels->first(
                fn (ChatChannel $channel): bool => $channel->id !== $chatChannel->id,
            );

            InlineAttachments::detachMany(
                (new ChatMessage)->getMorphClass(),
                $chatChannel->messages()->pluck('id'),
            );
            $chatChannel->delete();

            return $nextChannel instanceof ChatChannel ? $nextChannel->id : throw new \LogicException('A remaining channel was expected.');
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel deleted.')]);

        return to_route('projects.chat.show', [
            'current_team' => $currentTeam,
            'project' => $project,
            'channel' => $nextChannelId,
        ]);
    }
}
