import template from './muwa-backup-repository-create.html.twig';

const { Component } = Shopware;

Component.extend('muwa-backup-repository-create', 'muwa-backup-repository-detail', {

    template,

    data() {
        return {
            backupRepository: null,
            isLoading: false,
            isSaveSuccessful: false,
            type: [
                { value: 'files', label: this.$tc('muwa-backup-repository.general.types.files') },
                { value: 'completeDatabaseSingleFile', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSingleFile') },
                { value: 'completeDatabaseSeparateFiles', label: this.$tc('muwa-backup-repository.general.types.completeDatabaseSeparateFiles') }
            ]
        };
    },

    methods: {

        getBackupRepository() {

            this.backupRepository = this.repository.create(Shopware.Context.api);
            this.backupRepository.active = true;
            // this.backupRepository.name = '';
        },

        onClickSave() {

            // this.castValues();

            if (this.hasErrors()) {
                return;
            }

            this.isLoading = true;

            this.repository
                .save(this.backupRepository, Shopware.Context.api)
                .then(() => {

                    this.isLoading = false;
                    this.$router.push({ name: 'lightson.pseudo.product.detail', params: { id: this.backupRepository.id } });
                    this.createNotificationSuccess({
                        title: this.$t('lightson-pseudo-product.detail.success-title'),
                        message: this.$t('lightson-pseudo-product.detail.success-message')
                    });

                }).catch((exception) => {

                this.isLoading = false;
                this.createNotificationError({
                    title: this.$t('lightson-pseudo-product.detail.error-message'),
                    message: exception
                });
            });
        }
    }
});
