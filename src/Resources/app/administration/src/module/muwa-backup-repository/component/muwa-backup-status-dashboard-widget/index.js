import template from './muwa-backup-status-dashboard-widget.html.twig';
import './muwa-backup-status-dashboard-widget.scss';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

const CONFIG_DOMAIN = 'MuckiFacilityPlugin.config';
const CONFIG_KEY_SHOW_WIDGET = `${CONFIG_DOMAIN}.showDashboardWidget`;

// Last line of a successful `restic check`, see muckiware/restic integration tests.
// Same rule as PHP `Core\CheckStatus::isHealthy()`: case-insensitive "contains".
const CHECK_STATUS_SUCCESS = 'no errors were found';
const CHECK_STATUS_ERROR_PATTERN = /error|fatal|fail/i;

const MIRROR_STATUS_VARIANTS = { succeeded: 'success', failed: 'danger' };

/**
 * Dashboard card with the latest repository status (`muwa_backup_repository_stats`) of every
 * active backup repository — the same data the "Repository status" tab of the detail page shows.
 */
Component.register('muwa-backup-status-dashboard-widget', {

    template,

    compatConfig: Shopware.compatConfig,

    inject: [
        'repositoryFactory',
        'systemConfigApiService',
        'acl',
    ],

    data() {
        return {
            isEnabled: false,
            isLoading: false,
            backupRepositories: [],
            lastSucceededUploads: {},
        };
    },

    computed: {
        isVisible() {
            return this.isEnabled && this.acl.can('muwa_backup_repository.viewer');
        },

        backupRepositoryRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        backupRepositoryUploadsRepository() {
            return this.repositoryFactory.create('muwa_backup_repository_uploads');
        },

        backupRepositoryCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.getAssociation('backupRepositoryStats')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);
            // newest check per repository: an upload older than it is shown as warning
            criteria.getAssociation('backupRepositoryChecks')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);
            // newest upload entry per repository (any status); its date is shown in the cell
            criteria.getAssociation('uploads')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);

            return criteria;
        },

        statusItems() {
            return this.backupRepositories.map((backupRepository) => {
                const latestStats = backupRepository.backupRepositoryStats?.first?.() ?? null;
                const latestUpload = backupRepository.uploads?.first?.() ?? null;
                const latestCheck = backupRepository.backupRepositoryChecks?.first?.() ?? null;

                return {
                    id: backupRepository.id,
                    name: backupRepository.name,
                    lastBackupAt: latestStats?.createdAt ?? null,
                    totalSize: latestStats?.totalSize ?? null,
                    snapshotsCount: latestStats?.snapshotsCount ?? null,
                    checkStatus: latestStats?.checkStatus ?? null,
                    hasStats: latestStats !== null,
                    hasMirror: Boolean(backupRepository.mirrorS3ConnectionId),
                    mirrorStatus: latestUpload?.status ?? null,
                    mirrorAt: latestUpload?.finishedAt ?? latestUpload?.createdAt ?? null,
                    mirrorCreatedAt: latestUpload?.createdAt ?? null,
                    latestCheckAt: latestCheck?.createdAt ?? null,
                    lastSucceededAt: this.lastSucceededUploads[backupRepository.id] ?? null,
                };
            });
        },

        showMirrorColumn() {
            return this.statusItems.some((item) => item.hasMirror);
        },

        statusColumns() {
            const columns = [
                { property: 'name', label: 'muwa-backup-repository.dashboard.repositoryLabel', primary: true },
                { property: 'lastBackupAt', label: 'muwa-backup-repository.dashboard.lastBackupAtLabel' },
                { property: 'totalSize', label: 'muwa-backup-repository.list.totalFileRepositorySizeLabel' },
                { property: 'snapshotsCount', label: 'muwa-backup-repository.list.totalSnapshotsLabel' },
                { property: 'checkStatus', label: 'muwa-backup-repository.list.CheckStatusLabel' },
            ];

            if (this.showMirrorColumn) {
                columns.push({ property: 'mirrorStatus', label: 'muwa-backup-repository.dashboard.mirrorStatusLabel' });
            }

            return columns;
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            if (!this.acl.can('muwa_backup_repository.viewer')) {
                return;
            }

            this.isEnabled = await this.loadIsEnabled();
            if (!this.isEnabled) {
                return;
            }

            await this.fetchBackupRepositories();
        },

        async loadIsEnabled() {
            try {
                const values = await this.systemConfigApiService.getValues(CONFIG_DOMAIN);
                // The admin config form stores a boolean, `bin/console system:config:set` without
                // `--json` stores the string "true" — accept both.
                const value = values?.[CONFIG_KEY_SHOW_WIDGET];
                return value === true || value === 'true';
            } catch {
                // Missing system_config:read privilege — treat the widget as switched off.
                return false;
            }
        },

        async fetchBackupRepositories() {
            this.isLoading = true;
            try {
                this.backupRepositories = await this.backupRepositoryRepository.search(
                    this.backupRepositoryCriteria,
                    Context.api,
                );
                await this.fetchLastSucceededUploads();
            } catch {
                this.backupRepositories = [];
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Date of the last successful upload per mirrored repository (tooltip of the S3 cell).
         * The DAL cannot load a second, differently filtered `uploads` association, so this runs one
         * small query (limit 1) per mirrored repository - bounded by the page size of 25 repositories.
         */
        async fetchLastSucceededUploads() {
            const mirrored = Array.from(this.backupRepositories ?? [])
                .filter((backupRepository) => Boolean(backupRepository.mirrorS3ConnectionId));
            const entries = await Promise.all(mirrored.map(async (backupRepository) => {
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('backupRepositoryId', backupRepository.id));
                criteria.addFilter(Criteria.equals('status', 'succeeded'));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
                criteria.setLimit(1);
                try {
                    const result = await this.backupRepositoryUploadsRepository.search(criteria, Context.api);
                    const upload = result.first?.() ?? null;
                    return [backupRepository.id, upload?.finishedAt ?? upload?.createdAt ?? null];
                } catch {
                    return [backupRepository.id, null];
                }
            }));
            this.lastSucceededUploads = Object.fromEntries(entries);
        },

        isHealthyCheckStatus(checkStatus) {
            return typeof checkStatus === 'string'
                && checkStatus.toLowerCase().includes(CHECK_STATUS_SUCCESS);
        },

        formatDateTime(value) {
            return this.dateFilter(value, { hour: '2-digit', minute: '2-digit' });
        },

        checkStatusVariant(item) {
            if (!item.checkStatus) {
                return item.hasStats ? 'warning' : 'neutral';
            }
            if (this.isHealthyCheckStatus(item.checkStatus)) {
                return 'success';
            }
            if (CHECK_STATUS_ERROR_PATTERN.test(item.checkStatus)) {
                return 'danger';
            }
            return 'info';
        },

        checkStatusLabel(item) {
            if (!item.hasStats) {
                return this.$t('muwa-backup-repository.dashboard.noStats');
            }
            if (!item.checkStatus) {
                return this.$t('muwa-backup-repository.dashboard.noCheck');
            }
            if (this.isHealthyCheckStatus(item.checkStatus)) {
                return this.$t('muwa-backup-repository.dashboard.checkOk');
            }
            return item.checkStatus;
        },

        /**
         * The newest upload is older than the newest check: the last backup run was not mirrored
         * (yet) - e.g. worker not running or mirror still in progress.
         */
        isMirrorOutdated(item) {
            if (!item.mirrorCreatedAt || !item.latestCheckAt) {
                return false;
            }
            return new Date(item.mirrorCreatedAt).getTime() < new Date(item.latestCheckAt).getTime();
        },

        mirrorStatusVariant(item) {
            if (item.mirrorStatus === 'succeeded' && this.isMirrorOutdated(item)) {
                return 'warning';
            }
            return MIRROR_STATUS_VARIANTS[item.mirrorStatus] ?? 'info';
        },

        mirrorStatusLabel(item) {
            if (!item.hasMirror) {
                return this.$t('muwa-backup-repository.detail.statsNoValue');
            }
            if (!item.mirrorStatus) {
                return this.$t('muwa-backup-repository.dashboard.mirrorPending');
            }
            const label = this.$t(`muwa-backup-repository.mirror.status.${item.mirrorStatus}`);
            // status label + date of the newest upload entry, e.g. "Succeeded 16.11.2026, 03:12"
            return item.mirrorAt ? `${label} ${this.formatDateTime(item.mirrorAt)}` : label;
        },

        mirrorStatusTitle(item) {
            if (!item.hasMirror || !item.mirrorStatus) {
                return '';
            }
            const lastSucceeded = item.lastSucceededAt
                ? this.$t('muwa-backup-repository.dashboard.mirrorLastSucceeded', {
                    date: this.formatDateTime(item.lastSucceededAt),
                })
                : this.$t('muwa-backup-repository.dashboard.mirrorNeverSucceeded');
            if (!this.isMirrorOutdated(item)) {
                return lastSucceeded;
            }
            return `${lastSucceeded} - ${this.$t('muwa-backup-repository.dashboard.mirrorOutdated')}`;
        },

        formatBytes(value) {
            if (value === null || value === undefined) {
                return this.$t('muwa-backup-repository.detail.statsNoValue');
            }
            return Shopware.Utils.format.fileSize(value);
        },

        formatCount(value) {
            if (value === null || value === undefined) {
                return this.$t('muwa-backup-repository.detail.statsNoValue');
            }
            return value.toLocaleString();
        },
    },
});
