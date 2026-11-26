const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

/**
 * Formats a size in bytes like Shopware.Utils.format.fileSize (base 1024, next unit from 0.9 of it,
 * two decimals, e.g. "1.50GB"), but up to TB: the core stops at GB and renders "undefined" as unit
 * from about 0.9 TiB, which backup repositories can reach.
 *
 * Shared by the detail page and the dashboard widgets, so all of them format the same way.
 *
 * @param {number|null|undefined} bytes
 * @param {Function} translate the component's `$t`
 * @returns {string}
 */
export default function formatBytes(bytes, translate) {
    if (bytes === null || bytes === undefined) {
        return translate('muwa-backup-repository.detail.statsNoValue');
    }

    let value = Number(bytes);
    let unit = 0;
    while (unit < UNITS.length - 1 && value / 1024 >= 0.9) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(2)}${UNITS[unit]}`;
}
