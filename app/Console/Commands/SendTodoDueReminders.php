<?php

namespace App\Console\Commands;

use App\Models\Todo;
use App\Models\TodoReminderDelivery;
use App\Support\TodoReminderDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('todos:send-due-reminders')]
#[Description('Send one reminder for each incomplete to-do due today')]
class SendTodoDueReminders extends Command
{
    public function handle(TodoReminderDispatcher $reminders): int
    {
        $created = 0;
        $today = today();
        $tomorrow = $today->copy()->addDay();

        Todo::query()
            ->where('due_on', '>=', $today->toDateString())
            ->where('due_on', '<', $tomorrow->toDateString())
            ->whereNull('completed_at')
            ->whereNotNull('assignee_id')
            ->with('assignee')
            ->chunkById(400, function (Collection $todos) use (&$created, $today): void {
                $todos->each(function (Todo $todo) use (&$created, $today): void {
                    $assignee = $todo->assignee;

                    if (! $assignee || $assignee->notificationChannels('assignment_reminder') === []) {
                        return;
                    }

                    $delivery = TodoReminderDelivery::query()->firstOrCreate([
                        'todo_id' => $todo->id,
                        'user_id' => $assignee->id,
                        'reminder_on' => $today,
                    ]);

                    if ($delivery->wasRecentlyCreated) {
                        $created++;
                    }
                });
            });

        $dispatch = $reminders->dispatchPending();

        $this->info("{$created} due reminder(s) sent.");
        $this->info("{$dispatch['dispatched']} due reminder job(s) queued.");

        if ($dispatch['failed'] > 0) {
            $this->error("{$dispatch['failed']} due reminder job(s) failed to queue.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
