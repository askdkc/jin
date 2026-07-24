<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\CheckInNotificationDelivery;
use App\Models\CheckInPrompt;
use App\Support\CheckInNotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('check-ins:dispatch')]
#[Description('Create due check-in prompts and queue database notifications')]
class DispatchCheckIns extends Command
{
    public function handle(CheckInNotificationDispatcher $notifications): int
    {
        $createdCount = 0;

        CheckIn::query()
            ->whereNull('paused_at')
            ->where('next_run_at', '<=', now())
            ->select('id')
            ->chunkById(100, function (Collection $checkIns) use (&$createdCount): void {
                foreach ($checkIns as $dueCheckIn) {
                    $createdCount += DB::transaction(function () use ($dueCheckIn): int {
                        $checkIn = CheckIn::query()
                            ->with('targets:id')
                            ->lockForUpdate()
                            ->findOrFail($dueCheckIn->id);

                        if ($checkIn->paused_at !== null || $checkIn->next_run_at->isFuture()) {
                            return 0;
                        }

                        $scheduledFor = $checkIn->next_run_at->toImmutable();
                        $created = 0;

                        foreach ($checkIn->targets as $target) {
                            $prompt = CheckInPrompt::query()->firstOrCreate([
                                'check_in_id' => $checkIn->id,
                                'user_id' => $target->id,
                                'scheduled_for' => $scheduledFor,
                            ], [
                                'requested_at' => now(),
                            ]);

                            if ($prompt->wasRecentlyCreated) {
                                CheckInNotificationDelivery::query()->firstOrCreate([
                                    'check_in_prompt_id' => $prompt->id,
                                ]);
                                $created++;
                            }
                        }

                        $checkIn->update([
                            'next_run_at' => $checkIn->frequency->advance(
                                $scheduledFor,
                                $checkIn->timezone,
                                $checkIn->scheduledWeekdays(),
                            ),
                        ]);

                        return $created;
                    }, attempts: 3);
                }
            });

        $dispatch = $notifications->dispatchPending();

        $this->components->info("Created {$createdCount} check-in prompts.");
        $this->components->info("Queued {$dispatch['dispatched']} check-in notification deliveries.");

        if ($dispatch['failed'] > 0) {
            $this->components->error("Failed to queue {$dispatch['failed']} check-in notification deliveries.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
