<?php

use App\Enums\CheckInFrequency;
use Carbon\CarbonImmutable;

test('daily frequency advances to the next selected weekday', function () {
    $monday = CarbonImmutable::parse('2026-07-20 09:00:00', 'Asia/Tokyo');

    $next = CheckInFrequency::Daily->advance($monday, 'Asia/Tokyo', [1, 3, 5]);

    expect($next->setTimezone('Asia/Tokyo')->toDateString())->toBe('2026-07-22');
});

test('daily frequency rejects a schedule without a valid weekday', function (array $weekdays) {
    CheckInFrequency::Daily->advance(
        CarbonImmutable::parse('2026-07-20 09:00:00', 'Asia/Tokyo'),
        'Asia/Tokyo',
        $weekdays,
    );
})->with([
    'empty weekdays' => [[]],
    'out-of-range weekday' => [[8]],
])->throws(InvalidArgumentException::class);
