import template from './muwa-backup-repository-create.html.twig';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { debounce, createId, object: { cloneDeep } } = Shopware.Utils;

Component.extend('muwa-backup-repository-create', 'muwa-backup-repository-detail', {

    template,

    methods: {
        getBackupRepository() {

            this.backupRepository = this.repository.create(Shopware.Context.api);

            // set default values
            this.backupRepository.active = true;
            this.backupRepository.name = '';

        },

        onClickSave() {
            this.castValues();

            if (this.hasErrors()) {
                return;
            }

            this.isLoading = true;

            this.repository
                .save(this.backupRepository, Shopware.Context.api)
                .then(() => {
                    this.isLoading = false;
                    this.$router.push({ name: 'muwa.backup.repository.detail', params: { id: this.backupRepository.id } });
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
