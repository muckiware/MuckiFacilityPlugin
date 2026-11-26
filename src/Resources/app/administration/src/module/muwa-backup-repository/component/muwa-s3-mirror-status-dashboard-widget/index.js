import template from './muwa-s3-mirror-status-dashboard-widget.html.twig';
import './muwa-s3-mirror-status-dashboard-widget.scss';
import formatBytes from '../../helper/format-bytes';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

const CONFIG_DOMAIN = 'MuckiFacilityPlugin.config';
const CONFIG_KEY_SHOW_WIDGET = `${CONFIG_DOMAIN}.showS3MirrorDashboardWidget`;

const MIRROR_STATUS_VARIANTS = { succeeded: 'success', failed: 'danger', skipped: 'warning' };

/**
 * Dashboard card with the S3 transfer status (`muwa_backup_repository_uploads`) of every active
 * backup repository that has an S3 mirror target. Switched on separately from the backup status
 * card via the plugin setting `showS3MirrorDashboardWidget`.
 */
Component.register('muwa-s3-mirror-status-dashboard-widget', {

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
            // only repositories with an S3 mirror target
            criteria.addFilter(Criteria.not('AND', [Criteria.equals('mirrorS3ConnectionId', null)]));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.addAssociation('mirrorS3Connection');
            // newest check per repository: an upload older than it is shown as warning
            criteria.getAssociation('backupRepositoryChecks')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);
            // newest upload entry per repository (any status)
            criteria.getAssociation('uploads')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);

            return criteria;
        },

        statusItems() {
            return Array.from(this.backupRepositories ?? []).map((backupRepository) => {
                const latestUpload = backupRepository.uploads?.first?.() ?? null;
                const latestCheck = backupRepository.backupRepositoryChecks?.first?.() ?? null;

                return {
                    id: backupRepository.id,
                    name: backupRepository.name,
                    connectionName: backupRepository.mirrorS3Connection?.name ?? null,
                    bucket: backupRepository.mirrorS3Bucket ?? null,
                    prefix: backupRepository.mirrorS3Prefix ?? null,
                    status: latestUpload?.status ?? null,
                    message: latestUpload?.message ?? null,
                    runAt: latestUpload?.finishedAt ?? latestUpload?.startedAt ?? latestUpload?.createdAt ?? null,
                    createdAt: latestUpload?.createdAt ?? null,
                    filesUploaded: latestUpload?.filesUploaded ?? null,
                    filesDeleted: latestUpload?.filesDeleted ?? null,
                    bytesUploaded: latestUpload?.bytesUploaded ?? null,
                    latestCheckAt: latestCheck?.createdAt ?? null,
                    lastSucceededAt: this.lastSucceededUploads[backupRepository.id] ?? null,
                };
            });
        },

        statusColumns() {
            return [
                { property: 'name', label: 'muwa-backup-repository.dashboard.repositoryLabel', primary: true },
                { property: 'target', label: 'muwa-backup-repository.s3Dashboard.targetLabel' },
                { property: 'status', label: 'muwa-backup-repository.mirror.columns.status' },
                { property: 'runAt', label: 'muwa-backup-repository.s3Dashboard.lastRunLabel' },
                { property: 'lastSucceededAt', label: 'muwa-backup-repository.s3Dashboard.lastSucceededLabel' },
                { property: 'filesUploaded', label: 'muwa-backup-repository.mirror.columns.filesUploaded' },
                { property: 'filesDeleted', label: 'muwa-backup-repository.mirror.columns.filesDeleted' },
                { property: 'bytesUploaded', label: 'muwa-backup-repository.mirror.columns.bytesUploaded' },
            ];
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
         * Date of the last successful upload per repository. The DAL cannot load a second,
         * differently filtered `uploads` association, so this runs one small query (limit 1) per
         * repository - bounded by the page size of 25 repositories.
         */
        async fetchLastSucceededUploads() {
            const entries = await Promise.all(Array.from(this.backupRepositories ?? []).map(async (backupRepository) => {
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('backupRepositoryId', backupRepository.id));
                criteria.addFilter(Criteria.equals('status', 'succeeded'));
                criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
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

        formatDateTime(value) {
            if (!value) {
                return this.$t('muwa-backup-repository.detail.statsNoValue');
            }
            return this.dateFilter(value, { hour: '2-digit', minute: '2-digit' });
        },

        targetLabel(item) {
            const path = [item.bucket, item.prefix].filter(Boolean).join('/');
            return path || this.$t('muwa-backup-repository.detail.statsNoValue');
        },

        /**
         * The newest upload is older than the newest check: the last backup run was not mirrored
         * (yet) - e.g. worker not running or mirror still in progress.
         */
        isOutdated(item) {
            if (!item.createdAt || !item.latestCheckAt) {
                return false;
            }
            return new Date(item.createdAt).getTime() < new Date(item.latestCheckAt).getTime();
        },

        statusVariant(item) {
            if (!item.status) {
                return 'neutral';
            }
            if (item.status === 'succeeded' && this.isOutdated(item)) {
                return 'warning';
            }
            return MIRROR_STATUS_VARIANTS[item.status] ?? 'info';
        },

        statusLabel(item) {
            if (!item.status) {
                return this.$t('muwa-backup-repository.s3Dashboard.pending');
            }
            return this.$t(`muwa-backup-repository.mirror.status.${item.status}`);
        },

        statusTitle(item) {
            const parts = [];
            if (item.message) {
                parts.push(item.message);
            }
            if (this.isOutdated(item)) {
                parts.push(this.$t('muwa-backup-repository.s3Dashboard.outdated'));
            }
            return parts.join(' - ');
        },

        formatBytes(value) {
            return formatBytes(value, this.$t.bind(this));
        },

        formatCount(value) {
            if (value === null || value === undefined) {
                return this.$t('muwa-backup-repository.detail.statsNoValue');
            }
            return value.toLocaleString();
        },
    },
});
