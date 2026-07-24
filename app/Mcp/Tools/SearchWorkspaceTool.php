<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Todo;
use App\Support\RichText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('JIN全体のプロジェクト、任務、掲示、ドキュメント、ファイルを認証済み利用者の権限範囲内で横断検索します。')]
class SearchWorkspaceTool extends Tool
{
    public function __construct(private McpContext $context) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string', 'min:1', 'max:200'],
        ]);
        $context = $this->context->team($request, $input['team_slug']);

        if ($context === null) {
            return Response::error('認証済み利用者が所属するチームが見つかりません。');
        }

        [$team, $user] = $context;
        $projectIds = Project::query()
            ->visibleTo($user, $team)
            ->whereNull('archived_at')
            ->select('projects.id');
        $search = '%'.addcslashes($input['query'], '%_\\').'%';
        $projects = Project::query()
            ->whereIn('id', clone $projectIds)
            ->where(fn ($query) => $query
                ->where('name', 'like', $search)
                ->orWhere('description', 'like', $search))
            ->limit(10)
            ->get(['id', 'name', 'description']);
        $todos = Todo::query()
            ->with('todoList.project:id,name')
            ->whereHas('todoList', fn ($query) => $query->whereIn('project_id', clone $projectIds))
            ->where(fn ($query) => $query
                ->where('title', 'like', $search)
                ->orWhere('details', 'like', $search))
            ->limit(10)
            ->get();
        $messages = MessageBoardPost::query()
            ->with('project:id,name')
            ->whereIn('project_id', clone $projectIds)
            ->where(fn ($query) => $query
                ->where('title', 'like', $search)
                ->orWhere('body', 'like', $search))
            ->limit(10)
            ->get();
        $documents = Document::query()
            ->with('project:id,name')
            ->whereIn('project_id', clone $projectIds)
            ->where(fn ($query) => $query
                ->where('title', 'like', $search)
                ->orWhere('body', 'like', $search))
            ->limit(10)
            ->get();
        $files = StoredFile::query()
            ->with('project:id,name')
            ->whereIn('project_id', clone $projectIds)
            ->where('original_name', 'like', $search)
            ->limit(10)
            ->get();

        return Response::json([
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'summary' => $project->description,
            ]),
            'todos' => $todos->map(fn (Todo $todo): array => [
                'id' => $todo->id,
                'title' => $todo->title,
                'summary' => Str::limit(RichText::plainText($todo->details), 240),
                'project' => $todo->todoList->project->name,
            ]),
            'messages' => $messages->map(fn (MessageBoardPost $message): array => [
                'id' => $message->id,
                'title' => $message->title,
                'summary' => Str::limit(RichText::plainText($message->body), 240),
                'project' => $message->project->name,
            ]),
            'documents' => $documents->map(fn (Document $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'summary' => Str::limit(RichText::plainText($document->body), 240),
                'project' => $document->project->name,
            ]),
            'files' => $files->map(fn (StoredFile $file): array => [
                'id' => $file->id,
                'title' => $file->original_name,
                'mime_type' => $file->mime_type,
                'project' => $file->project->name,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_slug' => $schema->string()->description('対象チームのslug')->required(),
            'query' => $schema->string()->description('検索文字列')->required(),
        ];
    }
}
