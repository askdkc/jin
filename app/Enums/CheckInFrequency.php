<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum CheckInFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * @param  list<int>  $weekdays
     */
    public function advance(
        CarbonImmutable $scheduledFor,
        string $timezone,
        array $weekdays = [1, 2, 3, 4, 5],
    ): CarbonImmutable {
        $local = $scheduledFor->setTimezone($timezone);

        return (match ($this) {
            self::Daily => self::nextSelectedWeekday($local, $weekdays),
            self::Weekly => $local->addWeek(),
            self::Monthly => $local->addMonthNoOverflow(),
        })->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  list<int>  $weekdays
     */
    private static function nextSelectedWeekday(
        CarbonImmutable $scheduledFor,
        array $weekdays,
    ): CarbonImmutable {
        do {
            $scheduledFor = $scheduledFor->addDay();
        } while (! in_array($scheduledFor->dayOfWeekIso, $weekdays, true));

        return $scheduledFor;
    }
}
