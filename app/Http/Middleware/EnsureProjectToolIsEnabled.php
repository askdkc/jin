<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectToolIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if (! $project instanceof Project) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();
        $tool = match (true) {
            str_starts_with($routeName, 'projects.messages.') => 'messages',
            str_starts_with($routeName, 'projects.todos.'),
            str_starts_with($routeName, 'projects.todo-lists.') => 'todos',
            str_starts_with($routeName, 'projects.documents.'),
            str_starts_with($routeName, 'projects.files.'),
            str_starts_with($routeName, 'projects.materials.'),
            str_starts_with($routeName, 'projects.material-folders.'),
            str_starts_with($routeName, 'projects.external-links.') => 'materials',
            str_starts_with($routeName, 'projects.schedule.') => 'schedule',
            str_starts_with($routeName, 'projects.chat.') => 'chat',
            str_starts_with($routeName, 'projects.card-tables.'),
            str_starts_with($routeName, 'projects.card-columns.'),
            str_starts_with($routeName, 'projects.cards.') => 'card_tables',
            str_starts_with($routeName, 'projects.check-ins.') => 'check_ins',
            str_starts_with($routeName, 'projects.time-entries.') => 'timesheets',
            default => null,
        };

        if ($tool !== null) {
            abort_unless($project->usesTool($tool), 404);
        }

        return $next($request);
    }
}
