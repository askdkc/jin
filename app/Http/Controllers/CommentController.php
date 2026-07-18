<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\SaveCommentRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\NewTodoListCommentNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class CommentController extends Controller
{
    public function store(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
    ): RedirectResponse {
        $comment = DB::transaction(function () use ($request, $project, $messageBoardPost): Comment {
            $comment = $messageBoardPost->comments()->create([
                ...$request->validated(),
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
            ]);

            Activity::record(
                $project,
                $request->user(),
                ActivityType::CommentCreated,
                $comment,
                $messageBoardPost->title,
            );

            return $comment;
        });

        InlineAttachments::syncFromBody($comment, $project, $request->user()->id, $comment->body);

        $recipients = $messageBoardPost->subscribers()
            ->where('users.id', '<>', $request->user()->id)
            ->get();
        Notification::send(
            $recipients,
            new NewMessageCommentNotification($request->user(), $project, $messageBoardPost),
        );

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Message,
            $messageBoardPost->title,
            $messageBoardPost->id,
        );

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    public function update(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
        Comment $comment,
    ): RedirectResponse {
        $comment->update($request->validated());

        InlineAttachments::syncFromBody($comment, $project, $request->user()->id, $comment->body);

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Message,
            $messageBoardPost->title,
            $messageBoardPost->id,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment updated.')]);

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    public function destroy(
        Team $currentTeam,
        Project $project,
        MessageBoardPost $messageBoardPost,
        Comment $comment,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($comment->user_id === request()->user()->id || Gate::allows('update', $project), 403);

        $comment->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment deleted.')]);

        return to_route('projects.messages.show', [$currentTeam, $project, $messageBoardPost]);
    }

    public function storeTodo(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        $comment = DB::transaction(function () use ($request, $project, $todo): Comment {
            $comment = $todo->comments()->create([
                ...$request->validated(),
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
            ]);

            Activity::record(
                $project,
                $request->user(),
                ActivityType::TodoCommentCreated,
                $comment,
                $todo->title,
            );

            return $comment;
        });

        TodoActivityNotification::sendToUsers(
            [$todo->created_by, $todo->assignee_id],
            $request->user(),
            $project,
            $todo,
            TodoActivityNotification::Commented,
        );

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Todo,
            $todo->title,
        );

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function updateTodo(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
        Comment $comment,
    ): RedirectResponse {
        $comment->update($request->validated());

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Todo,
            $todo->title,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment updated.')]);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function destroyTodo(
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
        Comment $comment,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($comment->user_id === request()->user()->id || Gate::allows('update', $project), 403);

        $comment->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment deleted.')]);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function storeList(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
    ): RedirectResponse {
        $comment = DB::transaction(function () use ($request, $project, $todoList): Comment {
            $comment = $todoList->comments()->create([
                ...$request->validated(),
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
            ]);

            Activity::record(
                $project,
                $request->user(),
                ActivityType::TodoCommentCreated,
                $comment,
                $todoList->title,
            );

            return $comment;
        });

        // Whoever comments starts following the list, Basecamp-style, so they get replies.
        $todoList->subscribers()->syncWithoutDetaching([$request->user()->id]);

        $recipients = $todoList->subscribers()
            ->where('users.id', '<>', $request->user()->id)
            ->get();
        Notification::send(
            $recipients,
            new NewTodoListCommentNotification($request->user(), $project, $todoList),
        );

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Todo,
            $todoList->title,
        );

        return back(fallback: route('projects.todo-lists.show', [$currentTeam, $project, $todoList]));
    }

    public function updateList(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Comment $comment,
    ): RedirectResponse {
        $comment->update($request->validated());

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::Todo,
            $todoList->title,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment updated.')]);

        return back(fallback: route('projects.todo-lists.show', [$currentTeam, $project, $todoList]));
    }

    public function destroyList(
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Comment $comment,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($comment->user_id === request()->user()->id || Gate::allows('update', $project), 403);

        $comment->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment deleted.')]);

        return back(fallback: route('projects.todo-lists.show', [$currentTeam, $project, $todoList]));
    }

    public function storeFile(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
    ): RedirectResponse {
        $comment = $storedFile->comments()->create([
            ...$request->validated(),
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
        ]);

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::File,
            $storedFile->original_name,
            $storedFile->id,
        );

        return back(fallback: route('projects.files.show', [$currentTeam, $project, $storedFile]));
    }

    public function updateFile(
        SaveCommentRequest $request,
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
        Comment $comment,
    ): RedirectResponse {
        $comment->update($request->validated());

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $request->user(),
            $project,
            MentionNotification::File,
            $storedFile->original_name,
            $storedFile->id,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment updated.')]);

        return back(fallback: route('projects.files.show', [$currentTeam, $project, $storedFile]));
    }

    public function destroyFile(
        Team $currentTeam,
        Project $project,
        StoredFile $storedFile,
        Comment $comment,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($comment->user_id === request()->user()->id || Gate::allows('update', $project), 403);

        $comment->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment deleted.')]);

        return back(fallback: route('projects.files.show', [$currentTeam, $project, $storedFile]));
    }
}
