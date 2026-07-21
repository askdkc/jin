<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

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
        // A corrupt stored schedule must fail instead of trapping the scheduler in an infinite loop.
        for ($daysChecked = 0; $daysChecked < 7; $daysChecked++) {
            $scheduledFor = $scheduledFor->addDay();

            if (in_array($scheduledFor->dayOfWeekIso, $weekdays, true)) {
                return $scheduledFor;
            }
        }

        throw new InvalidArgumentException('Daily check-ins require at least one weekday from 1 through 7.');
    }
}
