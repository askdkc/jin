<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\SaveMessageBoardPostReactionRequest;
use App\Http\Requests\Phase3\SaveMessageBoardPostRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use App\Support\Reactions;
use App\Support\RichText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MessageBoardController extends Controller
{
    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $posts = $project->messageBoardPosts()
            ->withCount('comments')
            ->with('user:id,name')
            ->orderByDesc('pinned_at')
            ->latest('published_at')
            ->paginate(20)
            ->through(fn (MessageBoardPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'authorName' => $post->user?->name,
                'publishedAt' => $post->published_at->toISOString(),
                'pinned' => $post->pinned_at !== null,
                'snippet' => Str::limit(RichText::plainText($post->body), 140),
                'commentsCount' => $post->comments_count,
            ]);

        return Inertia::render('projects/messages/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'posts' => $posts,
        ]);
    }

    public function create(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/messages/Compose', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'post' => null,
            'members' => $this->memberOptions($project),
        ]);
    }

    public function edit(Team $currentTeam, Project $project, MessageBoardPost $messageBoardPost): Response
    {
        Gate::authorize('view', $project);
        abort_unless($this->canManage($messageBoardPost->user_id, $project), 403);

        return Inertia::render('projects/messages/Compose', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'post' => [
                'id' => $messageBoardPost->id,
                'title' => $messageBoardPost->title,
                'body' => $messageBoardPost->body,
            ],
            'members' => $this->memberOptions($project),
        ]);
    }

    public function show(Team $currentTeam, Project $project, MessageBoardPost $messageBoardPost): Response
    {
        Gate::authorize('view', $project);

        $canManageProject = Gate::allows('update', $project);
        $messageBoardPost->load([
            'user:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'subscribers' => fn ($query) => $query->whereKey(request()->user()->id),
            'reactors' => fn ($query) => $query->select('users.id', 'users.name'),
            'attachments' => fn ($query) => $query->with('uploader:id,name')->oldest(),
        ]);

        return Inertia::render('projects/messages/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'post' => $this->postData($messageBoardPost, $canManageProject),
            'members' => $this->memberOptions($project),
        ]);
    }

    public function store(SaveMessageBoardPostRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $post = DB::transaction(function () use ($request, $project): MessageBoardPost {
            $post = $project->messageBoardPosts()->create([
                ...$request->validated(),
                'user_id' => $request->user()->id,
                'published_at' => now(),
            ]);
            $post->subscribers()->attach($request->user()->id);

            InlineAttachments::syncFromBody($post, $project, $request->user()->id, $post->body);
            Activity::record($project, $request->user(), ActivityType::MessageCreated, $post, $post->title);

            return $post;
        });

        $this->notifyMentions($project, $request->user(), $post);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message posted.')]);

        return to_route('projects.messages.show', [$currentTeam, $project, $post]);
    }

    public function update(
        SaveMessageBoardPostRequest $request,
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        $messageBoardPost = DB::transaction(function () use ($request, $project, $messageBoardPost): MessageBoardPost {
            $messageBoardPost = MessageBoardPost::query()
                ->lockForUpdate()
                ->findOrFail($messageBoardPost->id);
            $messageBoardPost->update($request->validated());

            InlineAttachments::syncFromBody(
                $messageBoardPost,
                $project,
                $request->user()->id,
                $messageBoardPost->body,
            );

            return $messageBoardPost;
        });
        $this->notifyMentions($project, $request->user(), $messageBoardPost);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message updated.')]);

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    private function notifyMentions(Project $project, User $actor, MessageBoardPost $post): void
    {
        $mentioned = Mentions::extract($post->body, $project->members);

        MentionNotification::sendToUsers($mentioned, $actor, $project, MentionNotification::Message, $post->title, $post->id);
    }

    public function destroy(
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($this->canManage($messageBoardPost->user_id, $project), 403);

        DB::transaction(function () use ($messageBoardPost): void {
            InlineAttachments::detachMany(
                (new Comment)->getMorphClass(),
                $messageBoardPost->comments()->pluck('id'),
            );
            $messageBoardPost->comments()->delete();
            InlineAttachments::detach($messageBoardPost);
            $messageBoardPost->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message deleted.')]);

        return to_route('projects.messages.index', [$currentTeam, $project]);
    }

    public function togglePin(
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        $isPinned = $messageBoardPost->pinned_at === null;
        $messageBoardPost->update(['pinned_at' => $isPinned ? now() : null]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isPinned ? __('Message pinned.') : __('Message unpinned.'),
        ]);

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    public function toggleSubscription(
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        $userId = request()->user()->id;
        $isSubscribed = $messageBoardPost->subscribers()->whereKey($userId)->exists();

        if ($isSubscribed) {
            $messageBoardPost->subscribers()->detach($userId);
        } else {
            $messageBoardPost->subscribers()->attach($userId);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isSubscribed ? __('Message notifications disabled.') : __('Message notifications enabled.'),
        ]);

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    public function toggleReaction(
        SaveMessageBoardPostReactionRequest $request,
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        Reactions::toggle(
            $messageBoardPost->reactors(),
            $request->user()->id,
            (string) $request->validated('reaction'),
        );

        return back();
    }

    /** @return array<string, mixed> */
    private function postData(MessageBoardPost $post, bool $canManageProject): array
    {
        $userId = request()->user()->id;

        return [
            'id' => $post->id,
            'title' => $post->title,
            'body' => $post->body,
            'authorName' => $post->user?->name,
            'publishedAt' => $post->published_at->toISOString(),
            'pinned' => $post->pinned_at !== null,
            'subscribed' => $post->subscribers->isNotEmpty(),
            'notifyCount' => $post->subscribers()->count(),
            'canEdit' => $canManageProject || $post->user_id === $userId,
            'reactions' => Reactions::grouped($post->reactors, $userId),
            'attachments' => $post->attachments->map(fn (StoredFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mimeType' => $file->mime_type,
                'size' => $file->size,
                'uploaderName' => $file->uploader?->name,
                'createdAt' => $file->created_at?->toISOString(),
                'canDelete' => $canManageProject || $file->uploaded_by === $userId,
            ])->all(),
            'comments' => $post->comments->map(fn ($comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'authorName' => $comment->user?->name,
                'createdAt' => $comment->created_at?->toISOString(),
                'canDelete' => $canManageProject || $comment->user_id === $userId,
            ]),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function memberOptions(Project $project): array
    {
        return array_values(
            $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->all()
        );
    }

    private function canManage(?int $creatorId, Project $project): bool
    {
        /** @var User $user */
        $user = request()->user();

        return $creatorId === $user->id || Gate::allows('update', $project);
    }
}
