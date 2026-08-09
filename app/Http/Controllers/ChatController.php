<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageCreated;
use App\Http\Requests\Chat\ChatHistoryRequest;
use App\Http\Requests\Chat\ChatThreadRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * Display the project chat.
     */
    public function show(Request $request, Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $channelId = $request->integer('channel');
        $selectedChannel = $channelId > 0
            ? $project->chatChannels()->find($channelId)
            : null;
        $selectedChannel ??= $this->ensureDefaultChannel($project);
        $history = $this->historyPage($selectedChannel);

        return Inertia::render('projects/chat/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'channels' => $project->chatChannels()
                ->withCount(['messages' => fn ($query) => $query->whereNull('parent_id')])
                ->orderBy('name')
                ->get()
                ->map(fn (ChatChannel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'description' => $channel->description,
                    'messageCount' => $channel->messages_count,
                ]),
            'selectedChannel' => [
                'id' => $selectedChannel->id,
                'name' => $selectedChannel->name,
                'description' => $selectedChannel->description,
                'messageCount' => $selectedChannel->messages()->whereNull('parent_id')->count(),
            ],
            'messages' => $history['messages'],
            'historyCursor' => $history['nextCursor'],
        ]);
    }

    /**
     * Return older history or messages created after a known message.
     */
    public function history(ChatHistoryRequest $request, Team $currentTeam, Project $project): JsonResponse
    {
        $channel = $this->resolveChannel($project, $request->integer('channel_id'));

        if ($request->filled('after_id')) {
            $afterId = $request->integer('after_id');
            $messages = $channel->messages()
                ->with('user:id,name')
                ->withCount('replies')
                ->whereNull('parent_id')
                ->where('id', '>', $afterId)
                ->oldest('id')
                // The 101st row is a cheap sentinel for another sync request; no COUNT(*) needed.
                ->limit(101)
                ->get();

            $hasMore = $messages->count() > 100;

            return response()->json([
                'messages' => $messages
                    ->take(100)
                    ->map(fn (ChatMessage $message): array => $this->messageData($message))
                    ->values(),
                'hasMore' => $hasMore,
            ]);
        }

        $cursor = $request->validated('cursor');

        return response()->json($this->historyPage($channel, is_string($cursor) ? $cursor : null));
    }

    /**
     * Store a new chat message.
     */
    public function store(StoreChatMessageRequest $request, Team $currentTeam, Project $project): JsonResponse
    {
        $parent = $request->filled('parent_id')
            ? $project->chatMessages()
                ->whereNull('parent_id')
                ->findOrFail($request->integer('parent_id'))
            : null;
        $channel = $parent->channel
            ?? $this->resolveChannel($project, $request->integer('channel_id'));

        $message = $project->chatMessages()->create([
            'chat_channel_id' => $channel->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parent?->id,
            'body' => $request->validated('body'),
        ]);

        $message->load('user:id,name')->loadCount('replies');
        ChatMessageCreated::dispatch($message);

        return response()->json([
            'message' => $this->messageData($message),
        ], 201);
    }

    /**
     * Return the replies for a top-level message.
     */
    public function thread(
        ChatThreadRequest $request,
        Team $currentTeam,
        Project $project,
        ChatMessage $chatMessage,
    ): JsonResponse {
        $cursor = $request->validated('cursor');
        $channel = $chatMessage->channel ?? $this->ensureDefaultChannel($project);
        $history = $this->historyPage(
            $channel,
            is_string($cursor) ? $cursor : null,
            $chatMessage->id,
        );

        $chatMessage->load('user:id,name')->loadCount('replies');

        return response()->json([
            'message' => $this->messageData($chatMessage),
            ...$history,
        ]);
    }

    /**
     * Get one cursor page of chat history.
     *
     * @return array{messages: Collection<int, array<string, mixed>>, nextCursor: string|null}
     */
    private function historyPage(
        ChatChannel $channel,
        ?string $encodedCursor = null,
        ?int $parentId = null,
    ): array {
        $paginator = $channel->messages()
            ->with('user:id,name')
            ->withCount('replies')
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->latest('id')
            ->cursorPaginate(
                perPage: 50,
                cursor: Cursor::fromEncoded($encodedCursor),
            );

        return [
            // Cursor pagination reads newest-first; the UI receives each page chronologically.
            'messages' => $paginator->getCollection()
                ->reverse()
                ->map(fn (ChatMessage $message): array => $this->messageData($message))
                ->values(),
            'nextCursor' => $paginator->nextCursor()?->encode(),
        ];
    }

    /**
     * Convert a chat message to its client representation.
     *
     * @return array<string, mixed>
     */
    private function messageData(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'channelId' => $message->chat_channel_id,
            'parentId' => $message->parent_id,
            'body' => $message->body,
            'createdAt' => $message->created_at?->toISOString(),
            'replyCount' => (int) ($message->getAttribute('replies_count') ?? 0),
            'user' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ] : null,
        ];
    }

    private function resolveChannel(Project $project, int $channelId): ChatChannel
    {
        if ($channelId > 0) {
            return $project->chatChannels()->findOrFail($channelId);
        }

        return $this->ensureDefaultChannel($project);
    }

    private function ensureDefaultChannel(Project $project): ChatChannel
    {
        $channel = $project->chatChannels()->firstOrCreate(
            ['name' => 'general'],
            [
                'created_by' => $project->created_by,
                'description' => __('Project-wide conversation'),
            ],
        );

        if ($channel->wasRecentlyCreated) {
            // The migration repaired existing rows; only a newly created default can need this fallback.
            $project->chatMessages()
                ->whereNull('chat_channel_id')
                ->update(['chat_channel_id' => $channel->id]);
        }

        return $channel;
    }
}
