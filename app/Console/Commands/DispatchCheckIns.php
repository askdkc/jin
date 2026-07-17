<?php

namespace App\Console\Commands;

use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Notifications\CheckInDueNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('check-ins:dispatch')]
#[Description('Create due check-in prompts and queue database notifications')]
class DispatchCheckIns extends Command
{
    public function handle(): int
    {
        $createdCount = 0;

        CheckIn::query()
            ->whereNull('paused_at')
            ->where('next_run_at', '<=', now())
            ->select('id')
            ->chunkById(100, function (Collection $checkIns) use (&$createdCount): void {
                foreach ($checkIns as $dueCheckIn) {
                    $promptIds = DB::transaction(function () use ($dueCheckIn): array {
                        $checkIn = CheckIn::query()
                            ->with('targets:id')
                            ->lockForUpdate()
                            ->findOrFail($dueCheckIn->id);

                        if ($checkIn->paused_at !== null || $checkIn->next_run_at->isFuture()) {
                            return [];
                        }

                        $scheduledFor = $checkIn->next_run_at->toImmutable();
                        $promptIds = [];

                        foreach ($checkIn->targets as $target) {
                            $prompt = CheckInPrompt::query()->firstOrCreate([
                                'check_in_id' => $checkIn->id,
                                'user_id' => $target->id,
                                'scheduled_for' => $scheduledFor,
                            ], [
                                'requested_at' => now(),
                            ]);

                            if ($prompt->wasRecentlyCreated) {
                                $promptIds[] = $prompt->id;
                            }
                        }

                        $checkIn->update([
                            'next_run_at' => $checkIn->frequency->advance(
                                $scheduledFor,
                                $checkIn->timezone,
                                $checkIn->scheduledWeekdays(),
                            ),
                        ]);

                        return $promptIds;
                    }, attempts: 3);

                    $createdCount += count($promptIds);

                    CheckInPrompt::query()
                        ->with(['user', 'checkIn.project'])
                        ->findMany($promptIds)
                        ->each(function (CheckInPrompt $prompt): void {
                            $prompt->user?->notify(new CheckInDueNotification($prompt));
                        });
                }
            });

        $this->components->info("Created {$createdCount} check-in prompts.");

        return self::SUCCESS;
    }
}
