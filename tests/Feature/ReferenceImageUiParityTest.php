<?php

test('reference image pages keep their feature-specific semantic hierarchy', function () {
    $message = file_get_contents(resource_path('js/pages/projects/messages/Show.svelte'));
    $todos = file_get_contents(resource_path('js/pages/projects/todos/ListShow.svelte'));
    $kanban = file_get_contents(resource_path('js/pages/projects/card-tables/Index.svelte'));
    $kanbanColumn = file_get_contents(resource_path('js/pages/projects/card-tables/ColumnShow.svelte'));
    $materials = file_get_contents(resource_path('js/pages/projects/materials/Index.svelte'));
    $translations = json_decode(file_get_contents(lang_path('ja.json')), true, flags: JSON_THROW_ON_ERROR);
    $calendar = file_get_contents(resource_path('js/pages/calendar/Index.svelte'));
    $activity = file_get_contents(resource_path('js/pages/activity/Index.svelte'));

    $contracts = [
        'message continuous paper' => preg_match(
            '/<article[^>]*aria-labelledby="message-title"[^>]*>.*<h1[^>]*id="message-title"[^>]*>.*<section[^>]*aria-labelledby="message-comments-heading"[^>]*>.*<\/article>/s',
            $message,
        ) === 1,
        'message comments divider' => preg_match(
            '/<div[^>]*role="separator"[^>]*>.*<h2[^>]*id="message-comments-heading"[^>]*>/s',
            $message,
        ) === 1,
        'todo derived active and completed split' => preg_match(
            '/const activeTodos = \$derived\(.*!todo\.completed.*\);.*const completedTodos = \$derived\(.*todo\.completed.*\);/s',
            $todos,
        ) === 1,
        'todo completed disclosure' => preg_match(
            '/\{#each activeTodos as todo.*<details[^>]*>.*\{#each completedTodos as todo/s',
            $todos,
        ) === 1,
        'kanban stable accent palette' => str_contains($kanban, 'const columnAccents = [')
            && array_reduce(
                range(1, 5),
                fn (bool $matches, int $index): bool => $matches
                    && str_contains(
                        $kanban,
                        "{ bar: 'bg-chart-{$index}', tint: 'bg-chart-{$index}/15' }",
                    ),
                true,
            )
            && str_contains(
                $kanban,
                'columnAccents[index % columnAccents.length]',
            )
            && str_contains($kanban, 'columnAccent(columnIndex).tint')
            && preg_match(
                '/columnAccent\(columnIndex\)\s*\.bar/',
                $kanban,
            ) === 1,
        'kanban collapsed vertical rail' => preg_match(
            '/\{#snippet collapsedStrip\(.*w-12 shrink-0 self-stretch.*columnAccent\(columnIndex\)\.bar.*\{@render columnMenu\(table, column, columnIndex\)\}.*visibleCards\(column\)\.length.*writing-mode:\s*vertical-rl.*CardTableController\.toggleColumn\.form\(.*<Maximize2 class="size-4".*\{#each columnLayout\.collapsedColumns as column.*\{@render collapsedStrip\(/s',
            $kanban,
        ) === 1,
        'kanban pins collapsed rails outside the horizontal scroller' => str_contains(
            $kanban,
            'data-collapsed-column-rails',
        ) && str_contains(
            $kanban,
            'class="flex shrink-0 items-stretch gap-2 pt-4 pb-3"',
        ) && ! str_contains(
            $kanban,
            'class="ml-auto flex gap-2 self-stretch"',
        ),
        'kanban direct column collapse control' => preg_match(
            '/\{#snippet columnHeader\(.*CardTableController\.toggleColumn\.form\(.*aria-label=\{t\(\'Collapse column\'\)\}.*<Minimize2 class="size-4".*\{@render columnMenu\(/s',
            $kanban,
        ) === 1,
        'kanban column menu escapes board clipping' => str_contains(
            $kanban,
            'has-data-[state=open]:overflow-visible',
        ) && ! str_contains(
            $kanban,
            "'w-[min(26rem,88vw)] shrink-0 overflow-hidden rounded-lg'",
        ),
        'kanban auto-sizes four columns before horizontal scrolling' => str_contains(
            $kanban,
            "'min-w-72 grow shrink-0 basis-[calc((100%_-_1.5rem)/4)] rounded-md'",
        ) && str_contains(
            $kanban,
            'flex min-w-0 flex-1 items-start gap-2 overflow-x-auto',
        ),
        'kanban adds columns from the fixed board-edge button' => str_contains(
            $kanban,
            'class="mt-4 size-6 shrink-0 self-start p-0"',
        ) && substr_count(
            $kanban,
            'onclick={() => openNewColumn(table.id)}',
        ) === 1,
        'kanban has a dedicated triage row' => str_contains(
            $kanban,
            'visibleCards(table.triage)',
        ) && str_contains(
            $kanban,
            'openCreateCard(table.id, table.triage.id)',
        ),
        'kanban keeps system rails fixed and links to details' => preg_match(
            '/\{#snippet systemColumnStrip\(.*w-12 shrink-0.*writing-mode:\s*vertical-rl.*CardTableController\.showColumn\(/s',
            $kanban,
        ) === 1,
        'kanban column headings link to column details' => substr_count(
            $kanban,
            'href={CardTableController.showColumn([',
        ) >= 4,
        'kanban column details show compact cards and comments' => str_contains(
            $kanbanColumn,
            'aria-labelledby="column-cards-heading"',
        ) && str_contains(
            $kanbanColumn,
            'aria-labelledby="column-comments-heading"',
        ) && str_contains(
            $kanbanColumn,
            'CommentController.storeCardColumn.form(',
        ) && str_contains(
            $kanbanColumn,
            'let commentComposerOpen = $state(false)',
        ) && str_contains(
            $kanbanColumn,
            '{#if commentComposerOpen}',
        ) && str_contains(
            $kanbanColumn,
            'onclick={openCommentComposer}',
        ) && str_contains(
            $kanbanColumn,
            'onSuccess={() => (commentComposerOpen = false)}',
        ) && str_contains(
            $kanbanColumn,
            'CardTableController.storeCard.form(routeArguments)',
        ),
        'kanban fixes Not Now to the right of triage' => preg_match(
            '/class="flex min-w-0 items-stretch gap-3".*visibleCards\(table\.triage\).*systemColumnStrip\(\s*table,\s*table\.notNow,\s*\'min-h-32 border-l bg-muted\'/s',
            $kanban,
        ) === 1,
        'kanban fixes Done to the right of lower columns' => preg_match(
            '/class="flex min-w-0 items-stretch gap-3".*openNewColumn\(table\.id\).*systemColumnStrip\(\s*table,\s*table\.done,/s',
            $kanban,
        ) === 1,
        'kanban matches Basecamp header and triage proportions' => str_contains(
            $kanban,
            'class="shrink-0 border-b px-4 py-3"',
        ) && str_contains(
            $kanban,
            'class="break-words text-4xl font-bold tracking-tight"',
        ) && str_contains(
            $kanban,
            "'w-48 max-w-[75vw] shrink-0'",
        ) && str_contains(
            $kanban,
            'size="sm"',
        ) && str_contains(
            $kanban,
            'class="relative w-36"',
        ),
        'kanban keeps cards compact while Done fills the lower board' => str_contains(
            $kanban,
            'class="flex min-h-0 flex-1 flex-col"',
        ) && str_contains(
            $kanban,
            'class="flex min-h-0 min-w-0 flex-1 items-stretch gap-2 border-t pl-4"',
        ) && str_contains(
            $kanban,
            'class="flex items-center justify-between gap-2 px-3 py-2"',
        ) && str_contains(
            $kanban,
            "'min-h-10'",
        ),
        'materials creation menu fits Japanese labels' => str_contains(
            $materials,
            '<DropdownMenuContent align="start" class="min-w-64">',
        ),
        'materials toolbar has Japanese translations' => ($translations['New...'] ?? null) === '新規作成...'
            && ($translations['All files'] ?? null) === 'すべてのファイル',
        'gantt weekend today and grid layers' => preg_match(
            '/\{:else if view === \'gantt\'\}.*class="relative"\s+style=.*\{#if todayInGanttRange\}.*inset-y-0.*border-blue-500.*h-12.*ganttWeekendBands.*background-image:/s',
            $calendar,
        ) === 1,
        'activity projects nested within days' => str_contains(
            $activity,
            'projects: ProjectActivityGroup[]',
        )
            && preg_match(
                '/\{#each getActivityGroups\(\) as group.*\{#each group\.projects as projectGroup.*\{#each projectGroup\.activities as activity/s',
                $activity,
            ) === 1,
    ];

    $missingContracts = array_keys(array_filter(
        $contracts,
        fn (bool $matches): bool => ! $matches,
    ));

    expect($missingContracts)->toBeEmpty(
        'Missing UI parity contracts: '.implode(', ', $missingContracts),
    );
});
