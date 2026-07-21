import assert from 'node:assert/strict';
import test from 'node:test';
import {
    defaultScheduleEventTimes,
    halfHourOptions,
    scheduleEventEnd,
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
