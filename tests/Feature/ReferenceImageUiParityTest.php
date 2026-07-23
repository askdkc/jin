<?php

test('reference image pages keep their feature-specific semantic hierarchy', function () {
    $message = file_get_contents(resource_path('js/pages/projects/messages/Show.svelte'));
    $todos = file_get_contents(resource_path('js/pages/projects/todos/ListShow.svelte'));
    $kanban = file_get_contents(resource_path('js/pages/projects/card-tables/Index.svelte'));
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
            '/\{#snippet collapsedStrip\(.*writing-mode:\s*vertical-rl.*\{#each collapsedColumns as column.*\{@render collapsedStrip\(/s',
            $kanban,
        ) === 1,
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
