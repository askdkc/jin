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
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\InlineAttachments;
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
    private const POSITION_UPDATE_CHUNK_SIZE = 250;

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
            ->map(fn (CardTable $cardTable): array => [
                'id' => $cardTable->id,
                'name' => $cardTable->name,
                'canDelete' => $canManageProject,
                'columns' => $cardTable->cardColumns->map(fn (CardColumn $cardColumn): array => [
                    'id' => $cardColumn->id,
                    'name' => $cardColumn->name,
                    'position' => $cardColumn->position,
                    'collapsed' => $cardColumn->is_collapsed,
                    'canDelete' => $canManageProject,
                    'cards' => $cardColumn->cards->map(fn (Card $card): array => [
                        'id' => $card->id,
                        'title' => $card->title,
                        'description' => $card->description,
                        'assigneeId' => $card->assignee_id,
                        'assigneeName' => $card->assignee?->name,
                        'creatorName' => $card->creator?->name,
                        'dueOn' => $card->due_on?->toDateString(),
                        'position' => $card->position,
                        'version' => $card->version,
                        'canDelete' => $card->created_by === $userId || $canManageProject,
                    ])->all(),
                ])->all(),
            ]);

        return Inertia::render('projects/card-tables/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'tables' => $tables,
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
        $project->cardTables()->create([
            ...$request->validated(),
            'position' => ((int) $project->cardTables()->max('position')) + 1,
        ]);

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
        DB::transaction(function () use ($cardTable): void {
            InlineAttachments::detachMany(
                (new Card)->getMorphClass(),
                Card::query()
                    ->whereHas('cardColumn', fn ($query) => $query->where('card_table_id', $cardTable->id))
                    ->pluck('id'),
            );
            $cardTable->delete();
        });

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function storeColumn(
        StoreCardColumnRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
    ): RedirectResponse {
        $cardTable->cardColumns()->create([
            ...$request->validated(),
            'position' => ((int) $cardTable->cardColumns()->max('position')) + 1,
        ]);

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

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function moveColumn(
        MoveCardColumnRequest $request,
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $cardTable, $cardColumn): void {
            $columnIds = $cardTable->cardColumns()
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $cardColumn->id)
                ->values();
            $targetPosition = min((int) $request->validated('target_position'), $columnIds->count());
            $columnIds->splice($targetPosition, 0, [$cardColumn->id]);
            $this->normalizeColumnPositions($columnIds);
        }, attempts: 3);

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    public function toggleColumn(
        Team $currentTeam,
        Project $project,
        CardTable $cardTable,
        CardColumn $cardColumn,
    ): RedirectResponse {
        Gate::authorize('view', $cardTable);

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
        DB::transaction(function () use ($cardColumn, $cardTable): void {
            InlineAttachments::detachMany((new Card)->getMorphClass(), $cardColumn->cards()->pluck('id'));
            $cardColumn->delete();
            $this->normalizeColumnPositions(
                $cardTable->cardColumns()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        });

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
            $card = $cardColumn->cards()->create([
                ...$request->safe()->except('version'),
                'created_by' => $request->user()->id,
                'position' => ((int) $cardColumn->cards()->max('position')) + 1,
                'version' => 1,
            ]);

            InlineAttachments::syncFromBody(
                $card,
                $project,
                $request->user()->id,
                $card->description,
            );

            Activity::record($project, $request->user(), ActivityType::CardCreated, $card, $card->title);
        });

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
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
        }, attempts: 3);

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
            InlineAttachments::detach($card);
            $card->delete();
            $this->normalizeCardPositions(
                $cardColumn->cards()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        });

        return to_route('projects.card-tables.index', [$currentTeam, $project]);
    }

    /** @param Collection<int, int> $columnIds */
    private function normalizeColumnPositions(Collection $columnIds): void
    {
        if ($columnIds->isEmpty()) {
            return;
        }

        $this->updatePositions('card_columns', $columnIds);
    }

    /** @param Collection<int, int> $cardIds */
    private function normalizeCardPositions(Collection $cardIds): void
    {
        if ($cardIds->isEmpty()) {
            return;
        }

        $this->updatePositions('cards', $cardIds);
    }

    /** @param Collection<int, int> $ids */
    private function updatePositions(string $tableName, Collection $ids): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($tableName);
        $idColumn = $grammar->wrap('id');
        $positionColumn = $grammar->wrap('position');
        $updatedAtColumn = $grammar->wrap('updated_at');

        foreach ($ids->chunk(self::POSITION_UPDATE_CHUNK_SIZE) as $chunk) {
            $caseClauses = implode(' ', array_fill(0, $chunk->count(), 'WHEN ? THEN ?'));
            $wherePlaceholders = implode(', ', array_fill(0, $chunk->count(), '?'));

            // Bind every value: only hard-coded, grammar-quoted identifiers enter SQL.
            // CASE is update-only, so a concurrent delete cannot recreate a row.
            $sql = "UPDATE {$table} SET {$positionColumn} = CASE {$idColumn} {$caseClauses} ELSE {$positionColumn} END, {$updatedAtColumn} = ? WHERE {$idColumn} IN ({$wherePlaceholders})";
            $caseBindings = $chunk
                ->flatMap(fn (int $id, int $position): array => [$id, $position])
                ->values()
                ->all();

            $connection->update($sql, [
                ...$caseBindings,
                now(),
                ...$chunk->values()->all(),
            ]);
        }
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
