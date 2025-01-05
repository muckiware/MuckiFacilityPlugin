import template from './muwa-backup-repository-create.html.twig';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.register('muwa-backup-repository-create', {

    template,

    inject: [
        'repositoryFactory',
        'feature',
        'acl'
    ],

    data() {
        return {
            V6_5_0_0: false,
            V6_6_0_0: false,
            backupRepository: {},
            isLoading: false,
            isLoadingInit: false,
            type: [
                { value: 'noneDatabase', label: this.$tc('muwa-backup-repository.general.types.noneDatabase') },
                { value: 'completeDatabaseSingleFile', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSingleFile') },
                { value: 'completeDatabaseSeparateFiles', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSeparateFiles') }
            ],
            processSuccess: false,
            httpClient: null,
            requestInitRepository: '/_action/muwa/backup/repository/init'
        };
    },

    mixins: [
        Mixin.getByName('placeholder'),
        Mixin.getByName('notification')
    ],

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

    computed: {

        repository() {
            return this.repositoryFactory.create('muwa_backup_repository');
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
        },

        getBackupPaths() {

            console.log('getBackupPaths in computed', this.backupRepository);
            return this.backupRepository.backupPaths;
        },
    },

    methods: {

        createdComponent() {

            this.isLoading = true;
            this.isBackupProcessInProgress = true;
            this.getBackupRepository();
            // this.loadBackupsPaths();
        },

        getBackupRepository() {

            this.backupRepository = this.repository.create(Shopware.Context.api);

            // set default values
            this.backupRepository.id = createId();
            this.backupRepository.active = true;
            this.backupRepository.name = 'Backup';
            this.backupRepository.forgetDaily = 7;
            this.backupRepository.forgetWeekly = 5;
            this.backupRepository.forgetMonthly = 12;
            this.backupRepository.forgetYearly = 35;
            this.backupRepository.backupPaths= [];

            console.log('this.backupRepository', this.backupRepository);
        },

        onClickSave() {

            this.castValues();

            if (this.hasErrors()) {
                return;
            }

            this.isLoadingInit = true;

            this.httpClient.post(this.requestInitRepository, this.backupRepository, { headers: this.getApiHeader() }).then(() => {

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.create.success-title'),
                    message: this.$t('muwa-backup-repository.create.success-message')
                });

            }).catch((exception) => {

                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.create.error-message'),
                    message: exception.response.data.errors[0].detail
                });

            }).then(() => {

                this.repository.save(this.backupRepository, Shopware.Context.api).then(() => {

                    this.isLoadingInit = false;
                    // this.$router.push({ name: 'muwa.backup.repository.detail', params: { id: this.backupRepository.id } });
                    this.createNotificationSuccess({
                        title: this.$t('muwa.backup.repository.create.success-title'),
                        message: this.$t('muwa.backup.repository.create.success-message')
                    });

                }).catch((exception) => {

                    console.error('Not possible to save the backup repository');
                    console.error(exception);
                    this.isLoading = false;
                    this.createNotificationError({
                        title: this.$t('muwa.backup.repository.create.error-message'),
                        message: exception
                    });
                });
            });
        },

        onAddBackupPath() {

            console.log('onAddBackupPath');

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

            console.log('this.backupRepository.backupPaths', this.backupRepository.backupPaths);

            // this.loadBackupsPaths();
        },

        onDeleteBackupPath(id) {

            this.backupRepository.backupPaths = this.backupRepository.backupPaths.filter((backupPath) => {
                return backupPath.id !== id;
            });
        },

        loadBackupsPaths() {

            if(this.backupRepository && this.backupRepository.backupPaths) {

                this.backupRepository.backupPaths.forEach((backupPath) => {
                    if (!backupPath.id) {
                        backupPath.id = createId();
                    }
                });
            }
        },

        getBackupPaths() {

            console.log('getBackupPaths', this.backupRepository);
            return this.backupRepository.backupPaths;
        },

        backupPathExist() {

            if(this.backupRepository.backupPaths) {
                return this.backupRepository.backupPaths.length > 0;
            }
            return false;
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
