<?php

namespace App\Support;

use App\Jobs\QueueCheckInDueNotification;
use App\Models\CheckInNotificationDelivery;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CheckInNotificationDispatcher
{
    public function __construct(private Dispatcher $bus) {}

    /** @return array{dispatched: int, failed: int} */
    public function dispatchPending(): array
    {
        $dispatched = 0;
        $failed = 0;

        CheckInNotificationDelivery::query()
            ->whereNull('queued_at')
            ->whereNull('skipped_at')
            ->whereNull('dispatched_at')
            ->select('id')
            ->eachById(function (CheckInNotificationDelivery $delivery) use (&$dispatched, &$failed): void {
                $dispatchedAt = now();
                $claimed = CheckInNotificationDelivery::query()
                    ->whereKey($delivery->id)
                    ->whereNull('queued_at')
                    ->whereNull('skipped_at')
                    ->whereNull('dispatched_at')
                    ->update([
                        'dispatched_at' => $dispatchedAt,
                        'last_error' => null,
                    ]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    $this->bus->dispatch(new QueueCheckInDueNotification($delivery->id));
                    $dispatched++;
                } catch (Throwable $exception) {
                    $failed++;

                    CheckInNotificationDelivery::query()
                        ->whereKey($delivery->id)
                        ->where('dispatched_at', $dispatchedAt)
                        ->whereNull('queued_at')
                        ->whereNull('skipped_at')
                        ->update([
                            'dispatched_at' => null,
                            'last_error' => Str::limit($exception->getMessage(), 2000),
                        ]);

                    Log::error('Check-in notification job could not be dispatched.', [
                        'delivery_id' => $delivery->id,
                        'exception' => $exception::class,
                    ]);
                }
            }, 200);

        return [
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];
    }
}
