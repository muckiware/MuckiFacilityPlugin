import template from './muwa-backup-repository-detail.html.twig';
import './muwa-backup-repository-detail.scss';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.register('muwa-backup-repository-detail', {

    template,

    inject: [
        'repositoryFactory',
        'feature',
        'acl'
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
    },

    mixins: [
        Mixin.getByName('placeholder'),
        Mixin.getByName('notification')
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
            backupRepository: {
                backupPaths: []
            },
            isLoading: false,
            isSaveSuccessful: false,
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
            httpClient: null,
            backupRepositoryChecks: [],
            backupRepositorySnapshots: [],
        };
    },

    created() {

        if (this.feature.isActive('V6_6_0_0')) {
            this.V6_6_0_0 = true;
        }

        if (this.feature.isActive('V6_5_0_0') && !this.feature.isActive('V6_6_0_0')) {
            this.V6_5_0_0 = true;
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

        isV6600() {
            return this.V6_6_0_0;
        },
        isV6500() {
            return this.V6_5_0_0;
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
            this.isBackupProcessInProgress = true;
            this.getBackupRepository();
            this.fetchBackupRepositoryChecks();
            this.fetchBackupRepositorySnapshots();
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
                    title: this.$t('lightson-pseudo-product.detail.error-message-internal-name-required-title'),
                    message: this.$t('lightson-pseudo-product.detail.error-message-internal-name-required-message')
                });
                return true;
            }

            return false
        },

        onRefresh() {

            this.fetchBackupRepositoryChecks();
            this.fetchBackupRepositorySnapshots();
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
                    title: this.$t('lightson-pseudo-product.detail.error-message'),
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

        saveFinish() {
        },

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
            criteria.setLimit(10);
            return criteria;
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
            criteria.setLimit(10);
            return criteria;
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
        }
    }
});
