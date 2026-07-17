<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum CheckInFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function advance(CarbonImmutable $scheduledFor, string $timezone): CarbonImmutable
    {
        $local = $scheduledFor->setTimezone($timezone);

        return (match ($this) {
            self::Daily => $local->addDay(),
            self::Weekly => $local->addWeek(),
            self::Monthly => $local->addMonthNoOverflow(),
        })->setTimezone((string) config('app.timezone'));
    }
}
