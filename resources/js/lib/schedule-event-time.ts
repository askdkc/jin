export type ScheduleEventTimes = {
    startDate: string;
    startTime: string;
    endDate: string;
    endTime: string;
};

export type ScheduleEventDaySegment = {
    startMinute: number;
    endMinute: number;
    startsOnDate: boolean;
};

export const halfHourOptions = Array.from({ length: 48 }, (_, index) => {
    const hours = Math.floor(index / 2);
    const minutes = index % 2 === 0 ? '00' : '30';

    return `${String(hours).padStart(2, '0')}:${minutes}`;
});

const dateValue = (value: Date): string =>
    `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;

const timeValue = (value: Date): string =>
    `${String(value.getHours()).padStart(2, '0')}:${String(value.getMinutes()).padStart(2, '0')}`;

const rangeFromStart = (start: Date): ScheduleEventTimes => {
    const end = new Date(start.getTime() + 30 * 60 * 1000);

    return {
        startDate: dateValue(start),
        startTime: timeValue(start),
        endDate: dateValue(end),
        endTime: timeValue(end),
    };
};

export const defaultScheduleEventTimes = (
    selectedDate: string,
    now = new Date(),
): ScheduleEventTimes => {
    const [year, month, day] = selectedDate.split('-').map(Number);
    const start = new Date(
        year,
        month - 1,
        day,
        now.getHours(),
        Math.floor(now.getMinutes() / 30) * 30 + 30,
        0,
        0,
    );

    return rangeFromStart(start);
};

export const scheduleEventEnd = (
    startDate: string,
    startTime: string,
): Pick<ScheduleEventTimes, 'endDate' | 'endTime'> | null => {
    const start = new Date(`${startDate}T${startTime}:00`);

    if (Number.isNaN(start.getTime())) {
        return null;
    }

    const { endDate, endTime } = rangeFromStart(start);

    return { endDate, endTime };
};

export const scheduleEventLastDisplayDate = (endsAt: string): string => {
    const endDate = endsAt.slice(0, 10);

    if (endsAt.slice(11, 16) !== '00:00') {
        return endDate;
    }

    const [year, month, day] = endDate.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day - 1))
        .toISOString()
        .slice(0, 10);
};

const minuteOfDay = (value: string): number => {
    const [hour, minute] = value.slice(11, 16).split(':').map(Number);

    return hour * 60 + minute;
};

export const scheduleEventDaySegment = (
    startsAt: string,
    endsAt: string,
    date: string,
): ScheduleEventDaySegment | null => {
    const startDate = startsAt.slice(0, 10);
    const endDate = endsAt.slice(0, 10);

    if (startDate > date || endDate < date) {
        return null;
    }

    const startMinute = startDate === date ? minuteOfDay(startsAt) : 0;
    const endMinute = endDate === date ? minuteOfDay(endsAt) : 24 * 60;

    if (endMinute <= startMinute) {
        return null;
    }

    return { startMinute, endMinute, startsOnDate: startDate === date };
};
