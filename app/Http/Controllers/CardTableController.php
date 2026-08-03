<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase9\MoveCardColumnRequest;
use App\Http\Requests\Phase9\MoveCardRequest;
use App\Http\Requests\Phase9\SaveCardRequest;
use App\Http\Requests\Phase9\StoreCardColumnRequest;
use App\Http\Requests\Phase9\StoreCardTableRequest;
use App\Models\Activity;
use App\Models\Card;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\InlineAttachments;
use App\Support\OrderedPositions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CardTableController extends Controller
{
    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $userId = request()->user()->id;
        // Every delete policy below delegates to the same project permission.
        $canManageProject = Gate::allows('update', $project);

        $tables = $project->cardTables()
            ->with(['cardColumns' => fn ($columnQuery) => $columnQuery
                ->with(['cards' => fn ($cardQuery) => $cardQuery
                    ->with(['assignee:id,name', 'creator:id,name'])
                    ->orderBy('position')])
                ->orderBy('position')])
            ->orderBy('position')
            ->get()
            ->map(function (CardTable $cardTable) use ($userId, $canManageProject): array {
                $triage = $cardTable->cardColumns->firstWhere('is_triage', true);
                $notNow = $cardTable->cardColumns->firstWhere('is_not_now', true);
                $done = $cardTable->cardColumns->firstWhere('is_done', true);
                abort_unless($triage instanceof CardColumn, 500, 'Card table is missing its triage column.');
                abort_unless($notNow instanceof CardColumn, 500, 'Card table is missing its Not Now column.');
                abort_unless($done instanceof CardColumn, 500, 'Card table is missing its Done column.');

                return [
                    'id' => $cardTable->id,
                    'name' => $cardTable->name,
                    'canDelete' => $canManageProject,
                    'triage' => $this->columnData($triage, $userId, false),
                    'notNow' => $this->columnData($notNow, $userId, false),
                    'done' => $this->columnData($done, $userId, false),
                    'columns' => $cardTable->cardColumns
                        ->reject(fn (CardColumn $cardColumn): bool => $cardColumn->isSystem())
                        ->map(fn (CardColumn $cardColumn): array => $this->columnData(
                            $cardColumn,
                            $userId,
                            $canManageProject,
                        ))
                        ->values()
                        ->all(),
                ];
            });

        return Inertia::render('projects/card-tables/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'tables' => $tables,
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function showColumn(
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): Response {
        Gate::authorize('view', $project);

        $userId = request()->user()->id;
        $canManageProject = Gate::allows('update', $project);
        $cardColumn->load([
            'cards' => fn ($cardQuery) => $cardQuery
                ->with(['assignee:id,name', 'creator:id,name'])
                ->orderBy('position'),
            'comments' => fn ($commentQuery) => $commentQuery
                ->with('user:id,name')
                ->oldest(),
        ]);

        return Inertia::render('projects/card-tables/ColumnShow', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'table' => ['id' => $cardTable->id, 'name' => $cardTable->name],
            'column' => [
                ...$this->columnData($cardColumn, $userId, $canManageProject),
                'isTriage' => $cardColumn->is_triage,
                'isNotNow' => $cardColumn->is_not_now,
                'isDone' => $cardColumn->is_done,
                'comments' => $cardColumn->comments->map(fn (Comment $comment): array => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'authorName' => $comment->user?->name,
                    'createdAt' => $comment->created_at?->toISOString(),
                    'canDelete' => $canManageProject || $comment->user_id === $userId,
                ])->all(),
            ],
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function storeTable(
        StoreCardTableRequest $request,
        Team $currentTeam,
        Project $project,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lastPosition = $project->cardTables()->max('position');
            $cardTable = $project->cardTables()->create([
                ...$request->validated(),
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);
            $cardTable->cardColumns()->createMany([
                [
                    'name' => 'Triage',
                    'position' => 0,
                    'is_triage' => true,
                ],
                [
                    'name' => 'Not Now',
                    'position' => 0,
                    'is_collapsed' => true,
                    'is_not_now' => true,
                ],
                [
                    'name' => 'Done',
                    'position' => 0,
                    'is_collapsed' => true,
                    'is_done' => true,
                ],
            ]);
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function updateTable(
        StoreCardTableRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
    ): RedirectResponse {
        $cardTable->update($request->validated());

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function destroyTable(
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
    ): RedirectResponse {
        Gate::authorize('delete', $cardTable);
        DB::transaction(function () use ($project, $cardTable): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $cardTable = $project->cardTables()->lockForUpdate()->findOrFail($cardTable->id);
            $columnIds = $cardTable->cardColumns()
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $cardIds = Card::query()
                ->whereIn('card_column_id', $columnIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $commentIds = Comment::query()
                ->where('commentable_type', (new CardColumn)->getMorphClass())
                ->whereIn('commentable_id', $columnIds)
                ->pluck('id');
            InlineAttachments::detachMany((new Comment)->getMorphClass(), $commentIds);
            Comment::query()->whereIn('id', $commentIds)->delete();
            InlineAttachments::detachMany((new Card)->getMorphClass(), $cardIds);
            $cardTable->delete();
            OrderedPositions::resequence(
                new CardTable,
                $project->cardTables()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function storeColumn(
        StoreCardColumnRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $cardTable): void {
            $cardTable = CardTable::query()->lockForUpdate()->findOrFail($cardTable->id);
            $lastPosition = $cardTable->cardColumns()
                ->nonSystem()
                ->max('position');

            $cardTable->cardColumns()->create([
                ...$request->validated(),
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function updateColumn(
        StoreCardColumnRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        $cardColumn->update($request->validated());

        return back(fallback: route('projects.card-tables.index', [$currentTeam, $project]));
    }

    public function moveColumn(
        MoveCardColumnRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        abort_if($cardColumn->isSystem(), 404);

        DB::transaction(function () use ($request, $cardTable, $cardColumn): void {
            $cardTable = CardTable::query()->lockForUpdate()->findOrFail($cardTable->id);
            $columnIds = $cardTable->cardColumns()
                ->nonSystem()
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $cardColumn->id)
                ->values();
            $targetPosition = min((int) $request->validated('target_position'), $columnIds->count());
            $columnIds->splice($targetPosition, 0, [$cardColumn->id]);
            $this->normalizeColumnPositions($columnIds);
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function toggleColumn(
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        Gate::authorize('view', $cardTable);
        abort_if($cardColumn->isSystem(), 404);

        $cardColumn->update(['is_collapsed' => ! $cardColumn->is_collapsed]);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function destroyColumn(
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        Gate::authorize('delete', $cardTable);
        abort_if($cardColumn->isSystem(), 404);

        DB::transaction(function () use ($cardColumn, $cardTable): void {
            $cardTable = CardTable::query()->lockForUpdate()->findOrFail($cardTable->id);
            $cardColumn = $cardTable->cardColumns()->lockForUpdate()->findOrFail($cardColumn->id);
            $cardIds = $cardColumn->cards()->orderBy('id')->lockForUpdate()->pluck('id');
            $commentIds = $cardColumn->comments()->pluck('id');
            InlineAttachments::detachMany((new Comment)->getMorphClass(), $commentIds);
            $cardColumn->comments()->delete();
            InlineAttachments::detachMany((new Card)->getMorphClass(), $cardIds);
            $cardColumn->delete();
            $this->normalizeColumnPositions(
                $cardTable->cardColumns()
                    ->nonSystem()
                    ->orderBy('position')
                    ->orderBy('id')
                    ->pluck('id'),
            );
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function storeCard(
        SaveCardRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project, $cardColumn): void {
            $cardColumn = CardColumn::query()->lockForUpdate()->findOrFail($cardColumn->id);
            $lastPosition = $cardColumn->cards()->max('position');
            $card = $cardColumn->cards()->create([
                ...$request->safe()->except('version'),
                'created_by' => $request->user()->id,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
                'version' => 1,
            ]);

            InlineAttachments::syncFromBody(
                $card,
                $project,
                $request->user()->id,
                $card->description,
            );

            Activity::record($project, $request->user(), ActivityType::CardCreated, $card, $card->title);
        }, attempts: 5);

        return back(fallback: route('projects.card-tables.index', [$currentTeam, $project]));
    }

    public function updateCard(
        SaveCardRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
        Card $card,
    ): RedirectResponse {
        $version = (int) $request->validated('version');
        DB::transaction(function () use ($request, $project, $card, $version): void {
            $updated = Card::query()
                ->whereKey($card->id)
                ->where('version', $version)
                ->update([
                    ...$request->safe()->except('version'),
                    'version' => $version + 1,
                ]);

            $this->throwVersionConflictWhen($updated === 0);

            $card->refresh();
            InlineAttachments::syncFromBody(
                $card,
                $project,
                $request->user()->id,
                $card->description,
            );
        });

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function moveCard(
        MoveCardRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
        Card $card,
    ): RedirectResponse {
        $targetColumn = $cardTable->cardColumns()->findOrFail((int) $request->validated('target_column_id'));
        $version = (int) $request->validated('version');
        $requestedPosition = $request->validated('target_position');

        DB::transaction(function () use (
            $targetColumn,
            $version,
            $requestedPosition,
            $cardColumn,
            $card,
        ): void {
            CardColumn::query()
                ->whereIn('id', array_values(array_unique([$cardColumn->id, $targetColumn->id])))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $updated = Card::query()
                ->whereKey($card->id)
                ->where('card_column_id', $cardColumn->id)
                ->where('version', $version)
                ->update([
                    'card_column_id' => $targetColumn->id,
                    'version' => $version + 1,
                ]);

            $this->throwVersionConflictWhen($updated === 0);

            $sourceCardIds = Card::query()
                ->where('card_column_id', $cardColumn->id)
                ->whereKeyNot($card->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->values();

            $targetCardIds = $targetColumn->is($cardColumn)
                ? $sourceCardIds
                : Card::query()
                    ->where('card_column_id', $targetColumn->id)
                    ->whereKeyNot($card->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->values();

            $targetPosition = min(
                $requestedPosition === null ? $targetCardIds->count() : (int) $requestedPosition,
                $targetCardIds->count(),
            );
            $targetCardIds->splice($targetPosition, 0, [$card->id]);

            if (! $targetColumn->is($cardColumn)) {
                $this->normalizeCardPositions($sourceCardIds);
            }

            $this->normalizeCardPositions($targetCardIds);
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function destroyCard(
        Request $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
        Card $card,
    ): RedirectResponse {
        Gate::authorize('view', $cardTable);
        abort_unless($card->created_by === $request->user()->id || Gate::allows('delete', $cardTable), 403);

        DB::transaction(function () use ($card, $cardColumn): void {
            $cardColumn = CardColumn::query()->lockForUpdate()->findOrFail($cardColumn->id);
            $card = $cardColumn->cards()->lockForUpdate()->findOrFail($card->id);
            InlineAttachments::detach($card);
            $card->delete();
            $this->normalizeCardPositions(
                $cardColumn->cards()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        }, attempts: 5);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    /** @param Collection<int, int> $columnIds */
    private function normalizeColumnPositions(Collection $columnIds): void
    {
        if ($columnIds->isEmpty()) {
            return;
        }

        OrderedPositions::resequence(new CardColumn, $columnIds);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     position: int,
     *     collapsed: bool,
     *     canDelete: bool,
     *     cards: list<array{
     *         id: int,
     *         title: string,
     *         description: string|null,
     *         assigneeId: int|null,
     *         assigneeName: string|null,
     *         creatorName: string|null,
     *         dueOn: string|null,
     *         position: int,
     *         version: int,
     *         canDelete: bool
     *     }>
     * }
     */
    private function columnData(CardColumn $cardColumn, int $userId, bool $canDelete): array
    {
        return [
            'id' => $cardColumn->id,
            'name' => $cardColumn->name,
            'position' => $cardColumn->position,
            'collapsed' => $cardColumn->is_collapsed,
            'canDelete' => $canDelete,
            'cards' => array_values(
                $cardColumn->cards->map(fn (Card $card): array => [
                    'id' => $card->id,
                    'title' => $card->title,
                    'description' => $card->description,
                    'assigneeId' => $card->assignee_id,
                    'assigneeName' => $card->assignee?->name,
                    'creatorName' => $card->creator?->name,
                    'dueOn' => $card->due_on?->toDateString(),
                    'position' => $card->position,
                    'version' => $card->version,
                    'canDelete' => $card->created_by === $userId || $canDelete,
                ])->all(),
            ),
        ];
    }

    /** @param Collection<int, int> $cardIds */
    private function normalizeCardPositions(Collection $cardIds): void
    {
        if ($cardIds->isEmpty()) {
            return;
        }

        OrderedPositions::resequence(new Card, $cardIds);
    }

    /** @throws ValidationException */
    private function throwVersionConflictWhen(bool $condition): void
    {
        if ($condition) {
            throw ValidationException::withMessages([
                'version' => __('This card was changed by someone else. Reload and try again.'),
            ]);
        }
    }
}
