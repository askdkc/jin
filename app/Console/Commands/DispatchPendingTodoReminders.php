<?php

namespace App\Console\Commands;

use App\Support\TodoReminderDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('todos:dispatch-pending-reminders')]
#[Description('Queue pending to-do reminder deliveries')]
class DispatchPendingTodoReminders extends Command
{
    public function handle(TodoReminderDispatcher $reminders): int
    {
        $dispatch = $reminders->dispatchPending();

        $this->components->info("Queued {$dispatch['dispatched']} pending to-do reminder deliveries.");

        if ($dispatch['failed'] > 0) {
            $this->components->error("Failed to queue {$dispatch['failed']} pending to-do reminder deliveries.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
