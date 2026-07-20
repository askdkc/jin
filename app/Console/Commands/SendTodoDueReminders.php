<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Notifications\TodoActivityNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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

        Todo::query()
            ->whereDate('due_on', today())
            ->whereNull('completed_at')
            ->whereNotNull('assignee_id')
            ->with(['assignee', 'todoList.project'])
            ->lazyById()
            ->each(function (Todo $todo) use (&$sent): void {
                $assignee = $todo->assignee;

                if (! $assignee
                    || $assignee->notificationChannels('assignment_reminder') === []
                    || $assignee->notifications()
                        ->where('data->type', TodoActivityNotification::Due)
                        ->where('data->todoId', $todo->id)
                        ->whereDate('created_at', today())
                        ->exists()) {
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

        $this->info("{$sent} due reminder(s) sent.");

        return self::SUCCESS;
    }
}
