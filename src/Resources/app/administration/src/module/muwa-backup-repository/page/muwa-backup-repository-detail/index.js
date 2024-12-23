import template from './muwa-backup-repository-detail.html.twig';
import './muwa-backup-repository-detail.scss';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.register('muwa-backup-repository-detail', {

    template,

    inject: [
        'repositoryFactory',
        'feature'
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
            backupRepository: {
                backupPaths: null
            },
            isLoading: false,
            isSaveSuccessful: false,
            type: [
                { value: 'files', label: this.$tc('muwa-backup-repository.general.types.files') },
                { value: 'completeDatabaseSingleFile', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSingleFile') },
                { value: 'completeDatabaseSeparateFiles', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSeparateFiles') }
            ],
            backupPaths: [],
            forgetParameters: [],
        };
    },

    created() {

        console.log('this.feature', this.feature);

        if (this.feature.isActive('VUE3')) {
            console.log('VUE3 is active');
        } else {
            console.log('VUE3 is not active');
        }
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

            return [{
                    property: 'backupPath',
                    label: 'muwa-backup-repository.detail.backupPathLabel',
                    allowResize: true,
                    width: '100%',
            }];
        }
    },

    methods: {

        createdComponent() {

            this.isLoading = true;
            this.getBackupRepository();
            this.loadBackupsPaths();
        },

        getBackupRepository() {

            this.repository
                .get(this.$route.params.id, Shopware.Context.api, this.criteria)
                .then((entity) => {

                    console.log('entity', entity);
                    this.backupRepository = entity;
                    this.isLoading = false;
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

            console.log('onClickSave', this.backupRepository);

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

        saveFinish() {
        },

        loadBackupsPaths() {

            console.log('this.backupRepository.backupPaths in loadBackupsPaths()', this.backupRepository);
            if(this.backupRepository && this.backupRepository.backupPaths) {

                this.backupRepository.backupPaths.forEach((backupPath) => {
                    if (!backupPath.id) {
                        backupPath.id = createId();
                    }
                });
            }
        },

        onAddBackupPath() {

            if(!this.backupRepository.backupPaths) {
                this.backupRepository.backupPaths = [];
            }
            this.backupRepository.backupPaths.forEach(currentBackupPath => { currentBackupPath.position += 1; });
            this.backupRepository.backupPaths.unshift({
                id: createId(),
                isDefault: false,
                backupPath: '',
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
    }
});
