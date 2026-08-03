<?php

namespace App\Jobs;

use App\Models\CheckInNotificationDelivery;
use App\Models\User;
use App\Notifications\CheckInDueNotification;
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

class QueueCheckInDueNotification implements ShouldBeUnique, ShouldQueue
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
        $claimed = CheckInNotificationDelivery::query()
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

        $delivery = CheckInNotificationDelivery::query()
            ->with(['prompt.user', 'prompt.checkIn.project.team'])
            ->find($this->deliveryId);

        if (! $delivery instanceof CheckInNotificationDelivery) {
            return;
        }

        $prompt = $delivery->prompt;
        $user = $prompt->user;
        $checkIn = $prompt->checkIn;
        $project = $checkIn->project;

        if ($prompt->answered_at !== null) {
            $this->skip($delivery, $claimedAt, 'already_answered');

            return;
        }

        if (! $user instanceof User) {
            $this->skip($delivery, $claimedAt, 'user_removed');

            return;
        }

        if (! $checkIn->targets()->whereKey($user->id)->exists()) {
            $this->skip($delivery, $claimedAt, 'target_removed');

            return;
        }

        if (! $project->isVisibleTo($user)) {
            $this->skip($delivery, $claimedAt, 'project_access_revoked');

            return;
        }

        if ($user->notificationChannels() === []) {
            $this->skip($delivery, $claimedAt, 'notifications_disabled');

            return;
        }

        try {
            $user->notify(new CheckInDueNotification($prompt));
        } catch (Throwable $exception) {
            CheckInNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('processing_at', $claimedAt)
                ->whereNull('queued_at')
                ->whereNull('skipped_at')
                ->update([
                    'processing_at' => null,
                    'last_error' => Str::limit($exception->getMessage(), 2000),
                ]);

            Log::error('Check-in notification could not be queued.', [
                'delivery_id' => $delivery->id,
                'check_in_prompt_id' => $prompt->id,
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        CheckInNotificationDelivery::query()
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
        CheckInNotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->update([
                'processing_at' => null,
                'dispatched_at' => null,
                'last_error' => $exception === null
                    ? 'Check-in notification job failed.'
                    : Str::limit($exception->getMessage(), 2000),
            ]);
    }

    private function skip(
        CheckInNotificationDelivery $delivery,
        CarbonInterface $claimedAt,
        string $reason,
    ): void {
        CheckInNotificationDelivery::query()
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
