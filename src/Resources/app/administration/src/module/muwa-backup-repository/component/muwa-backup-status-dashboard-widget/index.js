import template from './muwa-backup-status-dashboard-widget.html.twig';
import './muwa-backup-status-dashboard-widget.scss';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

const CONFIG_DOMAIN = 'MuckiFacilityPlugin.config';
const CONFIG_KEY_SHOW_WIDGET = `${CONFIG_DOMAIN}.showDashboardWidget`;

// Last line of a successful `restic check`, see muckiware/restic integration tests.
const CHECK_STATUS_SUCCESS = 'no errors were found';
const CHECK_STATUS_ERROR_PATTERN = /error|fatal|fail/i;

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
        };
    },

    computed: {
        isVisible() {
            return this.isEnabled && this.acl.can('muwa_backup_repository.viewer');
        },

        backupRepositoryRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        backupRepositoryCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.getAssociation('backupRepositoryStats')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);

            return criteria;
        },

        statusItems() {
            return this.backupRepositories.map((backupRepository) => {
                const latestStats = backupRepository.backupRepositoryStats?.first?.() ?? null;

                return {
                    id: backupRepository.id,
                    name: backupRepository.name,
                    createdAt: latestStats?.createdAt ?? null,
                    totalSize: latestStats?.totalSize ?? null,
                    snapshotsCount: latestStats?.snapshotsCount ?? null,
                    checkStatus: latestStats?.checkStatus ?? null,
                    hasStats: latestStats !== null,
                };
            });
        },

        statusColumns() {
            return [
                { property: 'name', label: 'muwa-backup-repository.dashboard.repositoryLabel', primary: true },
                { property: 'createdAt', label: 'muwa-backup-repository.detail.statsCreatedAtLabel' },
                { property: 'totalSize', label: 'muwa-backup-repository.list.totalFileRepositorySizeLabel' },
                { property: 'snapshotsCount', label: 'muwa-backup-repository.list.totalSnapshotsLabel' },
                { property: 'checkStatus', label: 'muwa-backup-repository.list.CheckStatusLabel' },
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
            } catch {
                this.backupRepositories = [];
            } finally {
                this.isLoading = false;
            }
        },

        checkStatusVariant(item) {
            if (!item.checkStatus) {
                return item.hasStats ? 'warning' : 'neutral';
            }
            if (item.checkStatus === CHECK_STATUS_SUCCESS) {
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
            if (item.checkStatus === CHECK_STATUS_SUCCESS) {
                return this.$t('muwa-backup-repository.dashboard.checkOk');
            }
            return item.checkStatus;
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
