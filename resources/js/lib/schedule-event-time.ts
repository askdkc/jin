export type ScheduleEventTimes = {
    startDate: string;
    startTime: string;
    endDate: string;
    endTime: string;
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
