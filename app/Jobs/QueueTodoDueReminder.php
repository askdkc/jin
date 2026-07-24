<?php

namespace App\Jobs;

use App\Models\TodoReminderDelivery;
use App\Notifications\TodoActivityNotification;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class QueueTodoDueReminder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('notifications');
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        $claimedAt = now();
        $staleBefore = $claimedAt->copy()->subMinutes(4);
        $claimed = TodoReminderDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->whereNull('processing_at')
                    ->orWhere('processing_at', '<', $staleBefore);
            })
            ->update([
                'processing_at' => $claimedAt,
                'last_error' => null,
            ]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = TodoReminderDelivery::query()
            ->with(['todo.todoList.project.team', 'user'])
            ->find($this->deliveryId);

        if (! $delivery instanceof TodoReminderDelivery) {
            return;
        }

        $todo = $delivery->todo;
        $user = $delivery->user;
        $project = $todo->todoList->project;

        if ($todo->completed_at !== null) {
            $this->skip($delivery, $claimedAt, 'todo_completed');

            return;
        }

        if (! $todo->due_on?->isSameDay($delivery->reminder_on)) {
            $this->skip($delivery, $claimedAt, 'due_date_changed');

            return;
        }

        if (! $delivery->reminder_on->isToday()) {
            $this->skip($delivery, $claimedAt, 'reminder_date_expired');

            return;
        }

        if ($todo->assignee_id !== $delivery->user_id) {
            $this->skip($delivery, $claimedAt, 'assignee_changed');

            return;
        }

        if (! $project->isVisibleTo($user)) {
            $this->skip($delivery, $claimedAt, 'project_access_revoked');

            return;
        }

        if ($user->notificationChannels('assignment_reminder') === []) {
            $this->skip($delivery, $claimedAt, 'notifications_disabled');

            return;
        }

        try {
            $user->notify(new TodoActivityNotification(
                TodoActivityNotification::Due,
                null,
                $project,
                $todo,
            ));
        } catch (Throwable $exception) {
            TodoReminderDelivery::query()
                ->whereKey($delivery->id)
                ->where('processing_at', $claimedAt)
                ->whereNull('queued_at')
                ->whereNull('skipped_at')
                ->update([
                    'processing_at' => null,
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ]);

            Log::error('Todo due reminder notification could not be queued.', [
                'delivery_id' => $delivery->id,
                'todo_id' => $todo->id,
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        TodoReminderDelivery::query()
            ->whereKey($delivery->id)
            ->where('processing_at', $claimedAt)
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->update([
                'processing_at' => null,
                'queued_at' => now(),
                'last_error' => null,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        TodoReminderDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->update([
                'processing_at' => null,
                'last_error' => $exception === null
                    ? 'Todo due reminder job failed.'
                    : Str::limit($exception->getMessage(), 2000),
            ]);
    }

    private function skip(
        TodoReminderDelivery $delivery,
        CarbonInterface $claimedAt,
        string $reason,
    ): void {
        TodoReminderDelivery::query()
            ->whereKey($delivery->id)
            ->where('processing_at', $claimedAt)
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->update([
                'processing_at' => null,
                'skipped_at' => now(),
                'skip_reason' => $reason,
                'last_error' => null,
            ]);
    }
}
