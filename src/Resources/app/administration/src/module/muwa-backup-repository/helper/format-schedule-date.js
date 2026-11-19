/**
 * Formats a schedule time (UTC, ISO 8601) in the timezone of the schedule, because that is the
 * timezone the cron expression is written in. The timezone is appended, e.g. "(Europe/Berlin)".
 *
 * @param {string|null|undefined} value
 * @param {string|null|undefined} timezone IANA timezone of the schedule, `UTC` if empty
 * @returns {string}
 */
export default function formatScheduleDate(value, timezone) {
    if (!value) {
        return '';
    }
    const timeZone = timezone || 'UTC';
    return `${Shopware.Utils.format.date(value, { weekday: 'short', timeZone })} (${timeZone})`;
}

/**
 * Next run of a schedule as stored by the scheduler, or null if there is no enabled schedule.
 *
 * @param {Object|null|undefined} schedule `muwa_backup_schedule` entity
 * @returns {string|null}
 */
export function getNextScheduledRun(schedule) {
    if (!schedule?.enabled) {
        return null;
    }
    return schedule.nextExecutionTime ?? null;
}
