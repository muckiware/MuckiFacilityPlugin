/**
 * Formats a backup duration persisted in seconds: below one minute in seconds, below one hour as
 * minutes:seconds (e.g. "18:30 Min."), otherwise as hours:minutes:seconds (e.g. "1:05:09 Std.").
 *
 * Shared by the detail page and the dashboard widget, so both always format the same way.
 *
 * @param {number|null|undefined} seconds
 * @param {Function} translate the component's `$t`
 * @returns {string}
 */
export default function formatDuration(seconds, translate) {
    if (seconds === null || seconds === undefined) {
        return translate('muwa-backup-repository.detail.statsNoValue');
    }

    const totalSeconds = Math.round(seconds);
    if (totalSeconds < 60) {
        return translate('muwa-backup-repository.detail.durationSeconds', { value: totalSeconds.toLocaleString() });
    }

    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const secondsPart = pad(totalSeconds % 60);
    if (hours === 0) {
        return translate('muwa-backup-repository.detail.durationMinutes', { value: `${minutes}:${secondsPart}` });
    }
    return translate('muwa-backup-repository.detail.durationHours', {
        value: `${hours}:${pad(minutes)}:${secondsPart}`,
    });
}

function pad(value) {
    return String(value).padStart(2, '0');
}
