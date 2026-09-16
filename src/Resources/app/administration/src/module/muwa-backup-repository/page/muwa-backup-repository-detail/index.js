import template from './muwa-backup-repository-detail.html.twig';
import './muwa-backup-repository-detail.scss';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.register('muwa-backup-repository-detail', {

    template,

    compatConfig: Shopware.compatConfig,

    inject: [
        'repositoryFactory',
        'feature',
        'acl'
    ],

    emits: [
        'items-delete-finish',
    ],

    props: {
        check: {
            type: Object,
            required: true,
        },

        versionContext: {
            type: Object,
            required: true,
        },
        items: {
            type: Array,
            required: false,
            default: null,
        },
    },

    mixins: [
        Mixin.getByName('placeholder'),
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
    ],

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    data() {
        return {
            V6_5_0_0: false,
            V6_6_0_0: false,
            V6_7_0_0: false,
            backupRepository: {
                backupPaths: []
            },
            isLoading: false,
            isStatsLoading: false,
            showDeleteModal: false,
            isSaveSuccessful: false,
            // sw-button-process declares processSuccess as a required prop
            processSuccess: false,
            type: [
                { value: 'noneDatabase', label: this.$tc('muwa-backup-repository.general.types.noneDatabase') },
                { value: 'completeDatabaseSingleFile', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSingleFile') },
                { value: 'completeDatabaseSeparateFiles', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSeparateFiles') }
            ],
            isBackupProcessInProgress: false,
            isBackupProcessSuccess: false,
            isBackupProcessDisabled: true,
            requestBackupProcess: '/_action/muwa/backup/process',
            requestRestoreProcess: '/_action/muwa/restore/process',
            requestRemoveSnapshots: '/_action/muwa/remove/snapshots',
            httpClient: null,
            backupRepositoryChecks: [],
            backupRepositorySnapshots: [],
            selectedSnapshots: [],
            backupRepositoryStats: [],
            statsHistoryView: 'table',
            historyFilterFrom: null,
            historyFilterTo: null,
            historyActivePreset: null
        };
    },

    created() {

        if (this.feature.isActive('V6_6_0_0')) {
            this.V6_6_0_0 = true;
        }

        if (this.feature.isActive('V6_5_0_0') && !this.feature.isActive('V6_6_0_0')) {
            this.V6_5_0_0 = true;
        }

        if (this.feature.isActive('V6_7_0_0')) {
            this.V6_7_0_0 = true;
        }

        if(this.$route.params.tab === undefined) {
            this.$router.push({ name: 'muwa.backup.repository.detail', params: { tab: 'backupRepositoryConfig' } });
        }

        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.createdComponent();
    },

    computed: {

        repository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        backupRepositoryChecksRepository() {
            return this.repositoryFactory.create('muwa_backup_repository_checks');
        },

        backupRepositorySnapshotsRepository() {
            return this.repositoryFactory.create('muwa_backup_repository_snapshots');
        },

        backupRepositoryStatsRepository() {
            return this.repositoryFactory.create('muwa_backup_repository_stats');
        },

        latestStats() {

            if (this.backupRepositoryStats && this.backupRepositoryStats.length) {
                return this.backupRepositoryStats[0];
            }
            return null;
        },

        criteria() {
            const criteria = new Criteria();
            criteria.addAssociation('backupRepositoryChecks');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            return criteria;
        },

        getBackupPaths() {
            return this.backupRepository.backupPaths;
        },

        backupPathExist() {

            if(this.backupRepository.backupPaths) {
                return this.backupRepository.backupPaths.length > 0;
            }
            return false;
        },

        backupPathsColumns() {

            return [
                {
                    property: 'backupPath',
                    label: 'muwa-backup-repository.detail.backupPathLabel',
                    allowResize: true,
                    width: '95%',
                },
                {
                    property: 'compress',
                    label: 'muwa-backup-repository.detail.compressPathLabel',
                    allowResize: true,
                    width: '5%',
                }
            ];
        },

        currentStatsColumns() {

            return [
                {
                    property: 'name',
                    label: 'muwa-backup-repository.list.statsItemLabel',
                    allowResize: true,
                    width: '50%',
                },
                {
                    property: 'value',
                    label: 'muwa-backup-repository.list.statsItemValueLabel',
                    allowResize: true,
                    width: '50%',
                }
            ];
        },

        currentStatsItems() {

            if (!this.latestStats) {
                return [];
            }

            return [
                {
                    id: 'createdAt',
                    name: this.$tc('muwa-backup-repository.detail.statsCreatedAtLabel'),
                    value: this.dateFilter(this.latestStats.createdAt, { hour: '2-digit', minute: '2-digit' }),
                },
                {
                    id: 'fileSystemSize',
                    name: this.$tc('muwa-backup-repository.list.totalFileSystemSizeLabel'),
                    value: this.formatBytes(this.latestStats.fileSystemSize),
                },
                {
                    id: 'totalSize',
                    name: this.$tc('muwa-backup-repository.list.totalFileRepositorySizeLabel'),
                    value: this.formatBytes(this.latestStats.totalSize),
                },
                {
                    id: 'snapshotsCount',
                    name: this.$tc('muwa-backup-repository.list.totalSnapshotsLabel'),
                    value: this.formatCount(this.latestStats.snapshotsCount),
                },
                {
                    id: 'totalFileCount',
                    name: this.$tc('muwa-backup-repository.list.totalFilesLabel'),
                    value: this.formatCount(this.latestStats.totalFileCount),
                },
                {
                    id: 'checkStatus',
                    name: this.$tc('muwa-backup-repository.list.CheckStatusLabel'),
                    value: this.latestStats.checkStatus || this.$tc('muwa-backup-repository.detail.statsNoValue'),
                }
            ];
        },

        statsHistoryColumns() {

            return [
                {
                    property: 'createdAt',
                    label: 'muwa-backup-repository.detail.statsCreatedAtLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'fileSystemSize',
                    label: 'muwa-backup-repository.list.totalFileSystemSizeLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'totalSize',
                    label: 'muwa-backup-repository.list.totalFileRepositorySizeLabel',
                    allowResize: true,
                    width: '20%',
                },
                {
                    property: 'snapshotsCount',
                    label: 'muwa-backup-repository.list.totalSnapshotsLabel',
                    allowResize: true,
                    align: 'right',
                    width: '20%',
                },
                {
                    property: 'totalFileCount',
                    label: 'muwa-backup-repository.list.totalFilesLabel',
                    allowResize: true,
                    align: 'right',
                    width: '20%',
                }
            ];
        },

        backupChecksColumns() {

            return [
                {
                    property: 'checkStatus',
                    label: 'muwa-backup-repository.detail.checkStatusLabel',
                    allowResize: true,
                    width: '80%',
                },
                {
                    property: 'createdAt',
                    label: 'muwa-backup-repository.detail.checkCreatedAtLabel',
                    allowResize: true,
                    dataIndex: 'createdAt',
                    align: 'right',
                    width: '10%',
                }
            ];
        },

        backupSnapshotsColumns() {

            return [
                {
                    property: 'snapshotShortId',
                    label: 'muwa-backup-repository.detail.snapshotShortIdStatusLabel',
                    allowResize: true
                },
                {
                    property: 'paths',
                    label: 'muwa-backup-repository.detail.pathsLabel',
                    allowResize: true
                },
                {
                    property: 'hostname',
                    label: 'muwa-backup-repository.detail.hostnameLabel',
                    allowResize: true
                },
                {
                    property: 'size',
                    label: 'muwa-backup-repository.detail.sizeLabel',
                    allowResize: true
                },
                {
                    property: 'createdAt',
                    label: 'muwa-backup-repository.detail.createdAtLabel',
                    allowResize: true,
                    dataIndex: 'createdAt',
                    align: 'right'
                }
            ];
        },

        historyFilterPresetOptions() {
            // id/name for the 6.6 sw-select-field-deprecated renderer, value/label for 6.7's
            // mt-select — see typeOptions() above for why both key sets are needed. Values
            // are strings: select components round-trip native option values as strings.
            return [
                { id: '7', name: this.$tc('muwa-backup-repository.detail.historyFilterPreset7Days'), value: '7', label: this.$tc('muwa-backup-repository.detail.historyFilterPreset7Days') },
                { id: '14', name: this.$tc('muwa-backup-repository.detail.historyFilterPreset14Days'), value: '14', label: this.$tc('muwa-backup-repository.detail.historyFilterPreset14Days') },
                { id: '30', name: this.$tc('muwa-backup-repository.detail.historyFilterPreset30Days'), value: '30', label: this.$tc('muwa-backup-repository.detail.historyFilterPreset30Days') },
            ];
        },

        historyFilterActive() {
            return Boolean(this.historyFilterFrom || this.historyFilterTo);
        },

        statsHistorySeriesColors() {
            // sw-chart's defaultOptions hard-code stroke.colors to a single brand color,
            // which would paint every series line the same regardless of the series count.
            // Overriding it here keeps the line colors in sync with the legend swatches.
            return ['#008FFB', '#00E396'];
        },

        statsHistorySortedStats() {
            // ascending (oldest first), the criteria sorts DESC for the table.
            return [...this.backupRepositoryStats].sort((a, b) => new Date(a.createdAt) - new Date(b.createdAt));
        },

        statsHistoryCategories() {
            return this.statsHistorySortedStats.map((stat) => new Date(stat.createdAt).getTime());
        },

        statsHistoryXAxisOptions() {
            // type: 'category' + an explicit categories array (one entry per stat, built
            // from statsHistoryCategories) places exactly one discrete tick per stat entry,
            // matching the history table 1:1 — a continuous 'datetime' scale would instead
            // generate its own evenly-spaced ticks and repeat the same date across several
            // adjacent ticks whenever entries sit close together.
            return {
                type: 'category',
                categories: this.statsHistoryCategories,
                labels: { formatter: (value) => this.formatChartDate(value) },
            };
        },

        statsHistorySizeSeries() {

            return [
                {
                    name: this.$tc('muwa-backup-repository.list.totalFileSystemSizeLabel'),
                    data: this.statsHistorySortedStats.map((stat) => stat.fileSystemSize),
                },
                {
                    name: this.$tc('muwa-backup-repository.list.totalFileRepositorySizeLabel'),
                    data: this.statsHistorySortedStats.map((stat) => stat.totalSize),
                },
            ];
        },

        statsHistorySizeChartOptions() {

            return {
                colors: this.statsHistorySeriesColors,
                stroke: { colors: this.statsHistorySeriesColors },
                xaxis: this.statsHistoryXAxisOptions,
                yaxis: { labels: { formatter: (value) => this.formatBytes(value) } },
                tooltip: { x: { formatter: (value, opts) => this.formatChartTooltipDate(value, opts) }, y: { formatter: (value) => this.formatBytes(value) } },
            };
        },

        statsHistoryCountSeries() {

            return [
                {
                    name: this.$tc('muwa-backup-repository.list.totalSnapshotsLabel'),
                    data: this.statsHistorySortedStats.map((stat) => stat.snapshotsCount),
                },
                {
                    name: this.$tc('muwa-backup-repository.list.totalFilesLabel'),
                    data: this.statsHistorySortedStats.map((stat) => stat.totalFileCount),
                },
            ];
        },

        statsHistoryCountChartOptions() {

            return {
                colors: this.statsHistorySeriesColors,
                stroke: { colors: this.statsHistorySeriesColors },
                xaxis: this.statsHistoryXAxisOptions,
                yaxis: { labels: { formatter: (value) => this.formatCount(value) } },
                tooltip: { x: { formatter: (value, opts) => this.formatChartTooltipDate(value, opts) }, y: { formatter: (value) => this.formatCount(value) } },
            };
        },

        isV6600() {
            return this.V6_6_0_0;
        },
        isV6500() {
            return this.V6_5_0_0;
        },
        isV6700() {
            return this.V6_7_0_0;
        },

        /**
         * Options for the database dump type select.
         *
         * Both key sets are supplied on purpose: up to 6.6 sw-select-field renders
         * sw-select-field-deprecated, which reads `id` and `name`, whereas from 6.7
         * on it renders mt-select, which reads `value` and `label`.
         */
        typeOptions() {
            return this.type.map((option) => {
                return {
                    id: option.value,
                    name: option.label,
                    value: option.value,
                    label: option.label,
                };
            });
        },

        tab() {
            return this.$route.params.tab || 'backupRepositoryConfig';
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    methods: {

        createdComponent() {

            this.isLoading = true;
            this.isStatsLoading = true;
            this.isBackupProcessInProgress = true;
            this.getBackupRepository();
            this.fetchBackupRepositoryChecks();
            this.fetchBackupRepositorySnapshots();
            this.fetchBackupRepositoryStats();
        },

        getBackupRepository() {

            this.repository.get(this.$route.params.id, Shopware.Context.api, this.criteria).then((entity) => {

                this.backupRepository = entity;
                this.isLoading = false;
                this.isBackupProcessInProgress = false;
                this.isBackupProcessDisabled = false;
            });
        },

        castValues() {
            this.backupRepository.active = Boolean(this.backupRepository.active);
        },

        hasErrors() {

            if (this.backupRepository.internalName === '') {
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.detail.error-message-internal-name-required-title'),
                    message: this.$t('muwa-backup-repository.detail.error-message-internal-name-required-message')
                });
                return true;
            }

            return false
        },

        onStatsHistoryTabChange(tabItem) {
            this.statsHistoryView = tabItem.name;
        },

        onHistoryPresetSelect(value) {

            if (!value) {
                return;
            }

            // A rolling "now minus N days" window, not "local midnight minus N days":
            // the browser's own timezone and the admin user's configured timezone
            // (Shopware.Store.get('session').currentUser.timeZone) can differ, and
            // truncating to midnight in the wrong one shifts the displayed date by a day.
            const to = new Date();
            const from = new Date(to.getTime() - Number(value) * 24 * 60 * 60 * 1000);

            this.historyActivePreset = value;
            this.historyFilterFrom = from.toISOString();
            this.historyFilterTo = to.toISOString();
            this.refetchActiveTabHistory();
        },

        onHistoryFilterFromChange(value) {
            this.historyFilterFrom = value || null;
            this.historyActivePreset = null;
            this.refetchActiveTabHistory();
        },

        onHistoryFilterToChange(value) {
            this.historyFilterTo = value || null;
            this.historyActivePreset = null;
            this.refetchActiveTabHistory();
        },

        onHistoryFilterReset() {
            this.historyFilterFrom = null;
            this.historyFilterTo = null;
            this.historyActivePreset = null;
            this.refetchActiveTabHistory();
        },

        refetchActiveTabHistory() {
            // one shared date filter, re-targeted at whichever tab's entity is open —
            // see the sidebar filter item, which is now permanently mounted for all
            // three tabs instead of being toggled via v-if per tab.
            if (this.tab === 'backupRepositoryChecks') {
                this.fetchBackupRepositoryChecks();
            } else if (this.tab === 'backupRepositorySnapshots') {
                this.fetchBackupRepositorySnapshots();
            } else if (this.tab === 'backupRepositoryStats') {
                this.fetchBackupRepositoryStats();
            }
        },

        applyHistoryDateFilter(criteria) {

            if (!this.historyFilterActive) {
                criteria.setLimit(10);
                return criteria;
            }

            const range = {};
            if (this.historyFilterFrom) {
                range.gte = this.historyFilterFrom;
            }
            if (this.historyFilterTo) {
                // the picker stores the start of the selected day; extend to its end
                // so entries created later that same day are still included.
                const inclusiveTo = new Date(this.historyFilterTo);
                inclusiveTo.setHours(23, 59, 59, 999);
                range.lte = inclusiveTo.toISOString();
            }

            criteria.addFilter(Criteria.range('createdAt', range));
            // no fixed cap without a date filter's natural boundary — 500 is a
            // generous safety net against an unbounded query, not an expected count.
            criteria.setLimit(500);
            return criteria;
        },

        onRefresh() {

            this.fetchBackupRepositoryChecks();
            this.fetchBackupRepositorySnapshots();
            this.fetchBackupRepositoryStats();
        },

        onClickSave() {

            this.castValues();

            if (this.hasErrors()) {
                return;
            }

            this.isLoading = true;
            this.isSaveSuccessful = false;

            this.repository
                .save(this.backupRepository, Shopware.Context.api, this.criteria)
                .then(() => {

                    this.getBackupRepository();
                    this.isLoading = false;
                    this.isSaveSuccessful = true;

                }).catch((exception) => {

                this.isLoading = false;
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.detail.error-message'),
                    message: exception
                });
            });
        },

        onBackupProcess() {

            if (this.hasErrors()) {
                return;
            }

            this.isBackupProcessInProgress = true;
            this.isSaveSuccessful = false;

            this.httpClient.post(this.requestBackupProcess, this.backupRepository, { headers: this.getApiHeader() }).then(() => {

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.create.process-success-title'),
                    message: this.$t('muwa-backup-repository.create.process-success-message')
                });

                this.isBackupProcessInProgress = false;

            }).catch((exception) => {

                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.create.error-message'),
                    message: exception.response.data.errors[0].detail
                });

            });
        },

        saveFinish() {},

        onAddBackupPath() {

            if(this.backupRepository.backupPaths.length !== undefined && this.backupRepository.backupPaths.length >= 1) {
                this.backupRepository.backupPaths.forEach(currentBackupPath => { currentBackupPath.position += 1; });
            } else {
                this.backupRepository.backupPaths = [];
            }

            this.backupRepository.backupPaths.unshift({
                id: createId(),
                isDefault: false,
                backupPath: '',
                compress: false,
                position: 0
            });
        },

        onDeleteBackupPath(id) {

            this.backupRepository.backupPaths = this.backupRepository.backupPaths.filter((backupPath) => {
                return backupPath.id !== id;
            });
        },

        getApiHeader() {

            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
                'Content-Type': 'application/json'
            }
        },

        fetchBackupRepositoryChecks() {

            const criteria = this.createBackupRepositoryChecksCriteria();

            this.isLoading = true;
            return this.backupRepositoryChecksRepository.search(criteria, Context.api).then((collection) => {

                this.backupRepositoryChecks = collection;
                this.isLoading = false;
                return this.backupRepositoryChecks;
            });
        },

        createBackupRepositoryChecksCriteria() {

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.addFilter(Criteria.equals('backupRepositoryId', this.$route.params.id));
            return this.applyHistoryDateFilter(criteria);
        },

        fetchBackupRepositorySnapshots() {

            const criteria = this.createBackupRepositorySnapshotsCriteria();

            this.isLoading = true;
            return this.backupRepositorySnapshotsRepository.search(criteria, Context.api).then((collection) => {

                this.backupRepositorySnapshots = collection;
                this.isLoading = false;
                return this.backupRepositorySnapshots;
            });
        },

        createBackupRepositorySnapshotsCriteria() {

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.addFilter(Criteria.equals('backupRepositoryId', this.$route.params.id));
            return this.applyHistoryDateFilter(criteria);
        },

        restoreSnapshot(item) {

            if (this.hasErrors()) {
                return;
            }

            this.isBackupProcessInProgress = true;
            this.isSaveSuccessful = false;

            this.httpClient.post(this.requestRestoreProcess, item, { headers: this.getApiHeader() }).then(() => {

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.restore.process-success-title'),
                    message: this.$t('muwa-backup-repository.restore.process-success-message')
                });

                this.isBackupProcessInProgress = false;

            }).catch((exception) => {

                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.restore.error-message'),
                    message: exception.response.data.errors[0].detail
                });
            });
        },

        updateSelection(selection) {
            this.selectedSnapshots = selection;
        },

        onDelete(id) {
            this.showDeleteModal = id;
        },
        onCloseDeleteModal() {
            this.showDeleteModal = false;
        },

        onConfirmDelete(id, snapshotId) {

            let snapshotIds = {};
            snapshotIds[id] = {
                snapshotId: snapshotId,
                id: id
            };

            this.showDeleteModal = false;

            let payload = {
                backupRepositoryId: this.backupRepository.id,
                selectedSnapshots : snapshotIds
            }

            this.isLoading = true
            this.isStatsLoading = true

            this.httpClient.post(this.requestRemoveSnapshots, payload, { headers: this.getApiHeader() }).then(() => {

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.manage.delete-success-title'),
                    message: this.$t('muwa-backup-repository.manage.delete-success-message')
                });

                this.isBackupProcessInProgress = false;
                this.onRefresh();

            }).catch((exception) => {

                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.restore.error-message'),
                    message: exception.response.data.errors[0].detail
                });
            });
        },

        fetchBackupRepositoryStats() {

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.addFilter(Criteria.equals('backupRepositoryId', this.$route.params.id));
            this.applyHistoryDateFilter(criteria);

            this.isStatsLoading = true;
            return this.backupRepositoryStatsRepository.search(criteria, Context.api).then((collection) => {

                this.backupRepositoryStats = collection;
                this.isStatsLoading = false;
                return this.backupRepositoryStats;
            });
        },

        formatBytes(value) {

            // Shopware.Utils.format.fileSize(bytes, locale = 'de-DE') — das locale-Argument wird
            // bewusst weggelassen: Shopware.State ist in 6.7 deprecated, Shopware.Store gibt es in
            // 6.6 nicht. Der Default deckt beide Majors ohne Versionszweig ab.
            if (value === null || value === undefined) {
                return this.$tc('muwa-backup-repository.detail.statsNoValue');
            }
            return Shopware.Utils.format.fileSize(value);
        },

        formatCount(value) {

            if (value === null || value === undefined) {
                return this.$tc('muwa-backup-repository.detail.statsNoValue');
            }
            return value.toLocaleString();
        },

        formatChartDate(value) {
            // dateFilter defaults hour/minute to 'numeric' and only overrides options it
            // receives explicitly — passing them as undefined is required to drop the
            // time-of-day from the output, just setting year/month/day is not enough.
            return this.dateFilter(Number(value), {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: undefined,
                minute: undefined,
            });
        },

        formatChartTooltipDate(value, opts) {
            // ApexCharts does not reliably pass the hovered category's own value into
            // tooltip.x.formatter the way it does for xaxis.labels.formatter — looking it
            // up via dataPointIndex from our own categories list avoids that ambiguity.
            const index = opts && typeof opts.dataPointIndex === 'number' ? opts.dataPointIndex : null;
            const timestamp = index !== null ? this.statsHistoryCategories[index] : value;
            return this.dateFilter(Number(timestamp), { hour: '2-digit', minute: '2-digit' });
        },

        itemsDeleteFinish() {

            let payload = {
                backupRepositoryId: this.backupRepository.id,
                selectedSnapshots : this.selectedSnapshots
            }

            this.isLoading = true
            this.isStatsLoading = true

            this.httpClient.post(this.requestRemoveSnapshots, payload, { headers: this.getApiHeader() }).then(() => {

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.manage.delete-success-title'),
                    message: this.$t('muwa-backup-repository.manage.deletes-success-message')
                });

                this.isBackupProcessInProgress = false;
                this.onRefresh();

            }).catch((exception) => {

                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.restore.error-message'),
                    message: exception.response.data.errors[0].detail
                });
            });
        }
    }
});
