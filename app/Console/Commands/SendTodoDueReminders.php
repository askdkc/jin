<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\User;
use App\Notifications\TodoActivityNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

#[Signature('todos:send-due-reminders')]
#[Description('Send one database reminder for each incomplete to-do due today')]
class SendTodoDueReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sent = 0;
        $today = today();
        $tomorrow = $today->copy()->addDay();

        Todo::query()
            // The half-open range stays indexable and matches SQLite's date-cast storage.
            ->where('due_on', '>=', $today->toDateString())
            ->where('due_on', '<', $tomorrow->toDateString())
            ->whereNull('completed_at')
            ->whereNotNull('assignee_id')
            ->with(['assignee', 'todoList.project'])
            ->chunkById(400, function (Collection $todos) use (&$sent, $today, $tomorrow): void {
                // Load duplicate markers once per chunk instead of querying notifications per to-do.
                $existingReminders = DatabaseNotification::query()
                    ->where('notifiable_type', (new User)->getMorphClass())
                    ->whereIn('notifiable_id', $todos->pluck('assignee_id')->filter()->unique())
                    ->where('data->type', TodoActivityNotification::Due)
                    // Keep placeholders: PostgreSQL's JSON ->> scalar is text, unlike raw integer literals.
                    ->whereIn('data->todoId', $todos->pluck('id'))
                    ->where('created_at', '>=', $today)
                    ->where('created_at', '<', $tomorrow)
                    ->get(['notifiable_id', 'data'])
                    ->mapWithKeys(fn (DatabaseNotification $notification): array => [
                        $notification->notifiable_id.':'.data_get($notification->data, 'todoId') => true,
                    ]);

                $todos->each(function (Todo $todo) use (&$sent, $existingReminders): void {
                    $assignee = $todo->assignee;

                    if (! $assignee
                        || $assignee->notificationChannels('assignment_reminder') === []
                        || $existingReminders->has($assignee->id.':'.$todo->id)) {
                        return;
                    }

                    $assignee->notify(new TodoActivityNotification(
                        TodoActivityNotification::Due,
                        null,
                        $todo->todoList->project,
                        $todo,
                    ));
                    $sent++;
                });
            });

        $this->info("{$sent} due reminder(s) sent.");

        return self::SUCCESS;
    }
}
