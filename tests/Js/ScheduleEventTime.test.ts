import assert from 'node:assert/strict';
import test from 'node:test';
import {
    defaultScheduleEventTimes,
    halfHourOptions,
    scheduleEventDaySegment,
    scheduleEventEnd,
    scheduleEventLastDisplayDate,
} from '../../resources/js/lib/schedule-event-time.ts';

test('schedule event defaults use the next half-hour and handle day rollover', () => {
    assert.deepEqual(
        defaultScheduleEventTimes('2026-07-21', new Date(2026, 6, 21, 20, 33)),
        {
            startDate: '2026-07-21',
            startTime: '21:00',
            endDate: '2026-07-21',
            endTime: '21:30',
        },
    );
    assert.deepEqual(
        defaultScheduleEventTimes('2026-07-21', new Date(2026, 6, 21, 23, 45)),
        {
            startDate: '2026-07-22',
            startTime: '00:00',
            endDate: '2026-07-22',
            endTime: '00:30',
        },
    );
    assert.deepEqual(scheduleEventEnd('2026-07-21', '23:54'), {
        endDate: '2026-07-22',
        endTime: '00:24',
    });
    assert.equal(halfHourOptions.length, 48);
    assert.deepEqual(halfHourOptions.slice(-2), ['23:00', '23:30']);
});

test('multi-day events are segmented at each displayed day boundary', () => {
    const startsAt = '2026-07-31T23:00';
    const endsAt = '2026-08-02T01:00';

    assert.deepEqual(scheduleEventDaySegment(startsAt, endsAt, '2026-07-31'), {
        startMinute: 23 * 60,
        endMinute: 24 * 60,
        startsOnDate: true,
    });
    assert.deepEqual(scheduleEventDaySegment(startsAt, endsAt, '2026-08-01'), {
        startMinute: 0,
        endMinute: 24 * 60,
        startsOnDate: false,
    });
    assert.deepEqual(scheduleEventDaySegment(startsAt, endsAt, '2026-08-02'), {
        startMinute: 0,
        endMinute: 60,
        startsOnDate: false,
    });
    assert.equal(scheduleEventDaySegment(startsAt, endsAt, '2026-08-03'), null);
    assert.equal(
        scheduleEventDaySegment(
            '2026-08-01T23:00',
            '2026-08-02T00:00',
            '2026-08-02',
        ),
        null,
    );
    assert.equal(
        scheduleEventLastDisplayDate('2026-08-02T00:00'),
        '2026-08-01',
    );
    assert.equal(
        scheduleEventLastDisplayDate('2026-08-02T00:01'),
        '2026-08-02',
    );
});
