<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('schedule_events')
            ->select(['id', 'starts_at'])
            ->whereColumn('ends_at', '<=', 'starts_at')
            ->orderBy('id')
            ->chunkById(500, function (Collection $events): void {
                foreach ($events as $event) {
                    DB::table('schedule_events')
                        ->where('id', $event->id)
                        ->whereColumn('ends_at', '<=', 'starts_at')
                        ->update([
                            'ends_at' => CarbonImmutable::parse((string) $event->starts_at)
                                ->addMinutes(30)
                                ->toDateTimeString(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: corrected rows cannot be distinguished from intentional 30-minute events.
    }
};
