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
            requestBackupProcess: '/_action/muwa/backup/process',
            httpClient: null,
        };
    },

    created() {

        if (this.feature.isActive('V6_6_0_0')) {
            this.V6_6_0_0 = true;
        }

        if (this.feature.isActive('V6_5_0_0') && !this.feature.isActive('V6_6_0_0')) {
            this.V6_5_0_0 = true;
        }

        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.createdComponent();
    },

    watch: {
        'backupRepository.surchargeType'(value) {
            if (value === 'percental') {
                this.backupRepository.multiplierProductStatus = false;
            }
        },
    },

    computed: {

        repository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        criteria() {
            const criteria = new Criteria();
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

        isV6600() {
            return this.V6_6_0_0;
        },
        isV6500() {
            return this.V6_5_0_0;
        }
    },

    methods: {

        createdComponent() {

            this.isLoading = true;
            this.isBackupProcessInProgress = true;
            this.getBackupRepository();
            this.loadBackupsPaths();
        },

        getBackupRepository() {

            this.repository
                .get(this.$route.params.id, Shopware.Context.api, this.criteria)
                .then((entity) => {

                    this.backupRepository = entity;
                    this.isLoading = false;
                    this.isBackupProcessInProgress = false;
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
                    title: this.$t('muwa-backup-repository.create.success-title'),
                    message: this.$t('muwa-backup-repository.create.success-message')
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

        loadBackupsPaths() {

            // if(this.backupRepository && this.backupRepository.backupPaths) {
            //
            //     this.backupRepository.backupPaths.forEach((backupPath) => {
            //         if (!backupPath.id) {
            //             backupPath.id = createId();
            //         }
            //     });
            // }
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

            this.loadBackupsPaths();
        },

        onDeleteBackupPath(id) {

            this.backupRepository.backupPaths = this.backupRepository.backupPaths.filter((backupPath) => {
                return backupPath.id !== id;
            });

            this.loadBackupsPaths();
        },

        getApiHeader() {

            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
                'Content-Type': 'application/json'
            }
        }
    }
});
