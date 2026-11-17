import template from './muwa-s3-connection-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * S3 connections, embedded as tab `s3ConnectionList` in the backup repository list.
 * No listing mixin: it syncs with the route query, which the repository list already owns.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            s3Connections: null,
            isLoading: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('muwa_s3_connection');
        },

        backupRepositoryRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        columns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$t('muwa-backup-repository.s3Connection.nameLabel'),
                    allowResize: true,
                    primary: true,
                    routerLink: 'muwa.backup.repository.s3ConnectionDetail',
                }, {
                    property: 'provider',
                    dataIndex: 'provider',
                    label: this.$t('muwa-backup-repository.s3Connection.providerLabel'),
                    allowResize: true,
                }, {
                    property: 'endpoint',
                    dataIndex: 'endpoint',
                    label: this.$t('muwa-backup-repository.s3Connection.endpointLabel'),
                    allowResize: true,
                },
            ];
        },
    },

    created() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(1, 25);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.s3Connections = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        getProviderLabel(provider) {
            const key = `muwa-backup-repository.s3Connection.providers.${provider}`;
            const label = this.$t(key);

            return label === key ? provider : label;
        },

        /**
         * The FK is ON DELETE RESTRICT; checking first gives the user the names of the
         * backup configurations instead of a bare constraint error.
         */
        async onDelete(item) {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('mirrorS3ConnectionId', item.id));

            try {
                const usages = await this.backupRepositoryRepository.search(criteria, Shopware.Context.api);
                if (usages.total > 0) {
                    this.createNotificationError({
                        title: this.$t('global.default.error'),
                        message: this.$t('muwa-backup-repository.s3Connection.deleteInUse', {
                            names: Array.from(usages).map((usage) => usage.name).join(', '),
                        }),
                    });
                    return;
                }

                await this.repository.delete(item.id, Shopware.Context.api);
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-backup-repository.s3Connection.deleteSuccess'),
                });
            } catch (exception) {
                this.createNotificationError({
                    title: this.$t('global.default.error'),
                    message: exception?.response?.data?.errors?.[0]?.detail || exception?.message || String(exception),
                });
            }

            await this.getList();
        },
    },
};
