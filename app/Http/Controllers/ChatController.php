<?php

namespace App\Http\Controllers;

use App\Actions\Chat\CreateChatMessage;
use App\Events\ChatMessageCreated;
use App\Events\ChatMessageReactionUpdated;
use App\Http\Requests\Chat\ChatHistoryRequest;
use App\Http\Requests\Chat\ChatThreadRequest;
use App\Http\Requests\Chat\SaveChatMessageReactionRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Support\Reactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(private CreateChatMessage $createChatMessage) {}

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
        $canManageProject = Gate::allows('update', $project);
        $userId = $request->user()->id;
        $relatedTodos = $selectedChannel->todos()
            ->select(['id', 'todo_list_id', 'chat_channel_id', 'assignee_id', 'created_by', 'title', 'due_on', 'completed_at', 'position'])
            ->with(['todoList:id,title', 'assignee:id,name'])
            ->whereNull('parent_id')
            ->orderBy('completed_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $linkableTodos = Todo::query()
            ->select(['id', 'todo_list_id', 'chat_channel_id', 'assignee_id', 'created_by', 'title', 'due_on', 'completed_at', 'position'])
            ->with(['todoList:id,title', 'assignee:id,name'])
            ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
            ->whereNull('parent_id')
            ->whereNull('chat_channel_id')
            ->when(! $canManageProject, fn ($query) => $query->where('created_by', $userId))
            ->orderBy('completed_at')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $channelFiles = $project->storedFiles()
            ->select(['id', 'project_id', 'uploaded_by', 'attachable_type', 'attachable_id', 'original_name', 'mime_type', 'size', 'created_at'])
            ->with('uploader:id,name')
            ->where('attachable_type', (new ChatMessage)->getMorphClass())
            ->whereIn('attachable_id', $selectedChannel->messages()->select('id'))
            ->latest('id')
            ->limit(100)
            ->get();

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
                    'canManage' => $canManageProject || $channel->created_by === $userId,
                ]),
            'selectedChannel' => [
                'id' => $selectedChannel->id,
                'name' => $selectedChannel->name,
                'description' => $selectedChannel->description,
                'messageCount' => $selectedChannel->messages()->whereNull('parent_id')->count(),
                'canManage' => $canManageProject || $selectedChannel->created_by === $userId,
            ],
            'messages' => $history['messages'],
            'historyCursor' => $history['nextCursor'],
            'todoLists' => $project->todoLists()
                ->select(['id', 'project_id', 'title', 'is_loose', 'position'])
                ->orderByDesc('is_loose')
                ->orderBy('position')
                ->get()
                ->map(fn (TodoList $list): array => [
                    'id' => $list->id,
                    'title' => $list->title,
                ]),
            'relatedTodos' => $relatedTodos->map(
                fn (Todo $todo): array => $this->todoData($todo, $canManageProject || $todo->created_by === $userId),
            ),
            'linkableTodos' => $linkableTodos->map(
                fn (Todo $todo): array => $this->todoData($todo, true),
            ),
            'channelFiles' => $channelFiles->map(fn (StoredFile $file): array => $this->fileData($file)),
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'maxChatAttachments' => (int) config('uploads.max_files_per_request'),
            'currentUserId' => $userId,
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
                ->with(['user:id,name', 'attachments.uploader:id,name', 'reactors:id,name'])
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

        $attachmentIds = $request->validated('attachment_ids') ?? [];
        $message = $this->createChatMessage->handle(
            $project,
            $channel,
            $request->user(),
            (string) ($request->validated('body') ?? ''),
            $parent,
            is_array($attachmentIds) ? array_values(array_map('intval', $attachmentIds)) : [],
        );

        $message->load(['user:id,name', 'attachments.uploader:id,name', 'reactors:id,name'])->loadCount('replies');
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

        $chatMessage->load(['user:id,name', 'attachments.uploader:id,name', 'reactors:id,name'])->loadCount('replies');

        return response()->json([
            'message' => $this->messageData($chatMessage),
            ...$history,
        ]);
    }

    /**
     * Toggle the authenticated user's reaction on a chat message.
     */
    public function toggleReaction(
        SaveChatMessageReactionRequest $request,
        Team $currentTeam,
        Project $project,
        ChatMessage $chatMessage,
    ): JsonResponse {
        Gate::authorize('view', $project);

        Reactions::toggleMultiple(
            $chatMessage->reactors(),
            $request->user()->id,
            $request->validated('reaction'),
        );
        $chatMessage->load('reactors:id,name');
        ChatMessageReactionUpdated::dispatch($chatMessage);

        return response()->json([
            'messageId' => $chatMessage->id,
            'reactions' => Reactions::grouped($chatMessage->reactors, $request->user()->id),
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
            ->with(['user:id,name', 'attachments.uploader:id,name', 'reactors:id,name'])
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
            'reactions' => Reactions::grouped($message->reactors, request()->user()->id),
            'user' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ] : null,
            'attachments' => $message->attachments
                ->map(fn (StoredFile $file): array => $this->fileData($file))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function todoData(Todo $todo, bool $canEdit): array
    {
        return [
            'id' => $todo->id,
            'listId' => $todo->todo_list_id,
            'listTitle' => $todo->todoList->title,
            'title' => $todo->title,
            'completed' => $todo->completed_at !== null,
            'assigneeName' => $todo->assignee?->name,
            'dueOn' => $todo->due_on?->toDateString(),
            'canEdit' => $canEdit,
        ];
    }

    /** @return array<string, mixed> */
    private function fileData(StoredFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'mimeType' => $file->mime_type,
            'size' => $file->size,
            'uploaderName' => $file->uploader?->name,
            'createdAt' => $file->created_at?->toISOString(),
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
