/**
 * Formats a backup duration persisted in seconds in the largest fitting unit: below one minute in
 * seconds, below one hour in minutes, otherwise in hours (max. one decimal place).
 *
 * Shared by the detail page and the dashboard widget, so both always scale the same way.
 *
 * @param {number|null|undefined} seconds
 * @param {Function} translate the component's `$t`
 * @returns {string}
 */
export default function formatDuration(seconds, translate) {
    if (seconds === null || seconds === undefined) {
        return translate('muwa-backup-repository.detail.statsNoValue');
    }
    if (seconds < 60) {
        return translate('muwa-backup-repository.detail.durationSeconds', { value: seconds.toLocaleString() });
    }

    // round to one decimal place before picking the unit, otherwise 3599 s would show as "60 min"
    const formatOptions = { maximumFractionDigits: 1 };
    const minutes = Math.round(seconds / 6) / 10;
    if (minutes < 60) {
        return translate('muwa-backup-repository.detail.durationMinutes', {
            value: minutes.toLocaleString(undefined, formatOptions),
        });
    }
    return translate('muwa-backup-repository.detail.durationHours', {
        value: (Math.round(seconds / 360) / 10).toLocaleString(undefined, formatOptions),
    });
}
