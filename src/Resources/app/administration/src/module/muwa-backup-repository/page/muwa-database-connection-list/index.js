import template from './muwa-database-connection-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Database connections, embedded as tab `databaseConnectionList` in the backup repository list.
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
            databaseConnections: null,
            isLoading: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('muwa_database_connection');
        },

        backupRepositoryRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        columns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$t('muwa-backup-repository.databaseConnection.nameLabel'),
                    allowResize: true,
                    primary: true,
                    routerLink: 'muwa.backup.repository.databaseConnectionDetail',
                }, {
                    property: 'type',
                    dataIndex: 'type',
                    label: this.$t('muwa-backup-repository.databaseConnection.typeLabel'),
                    allowResize: true,
                }, {
                    property: 'host',
                    dataIndex: 'host',
                    label: this.$t('muwa-backup-repository.databaseConnection.hostLabel'),
                    allowResize: true,
                }, {
                    property: 'databaseName',
                    dataIndex: 'databaseName',
                    label: this.$t('muwa-backup-repository.databaseConnection.databaseNameLabel'),
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
                this.databaseConnections = result;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        getTypeLabel(type) {
            const key = `muwa-backup-repository.databaseConnection.types.${type}`;
            const label = this.$t(key);

            return label === key ? type : label;
        },

        /**
         * The FK is ON DELETE RESTRICT; checking first gives the user the names of the
         * backup configurations instead of a bare constraint error.
         */
        async onDelete(item) {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('databaseConnectionId', item.id));

            try {
                const usages = await this.backupRepositoryRepository.search(criteria, Shopware.Context.api);
                if (usages.total > 0) {
                    this.createNotificationError({
                        title: this.$t('global.default.error'),
                        message: this.$t('muwa-backup-repository.databaseConnection.deleteInUse', {
                            names: Array.from(usages).map((usage) => usage.name).join(', '),
                        }),
                    });
                    return;
                }

                await this.repository.delete(item.id, Shopware.Context.api);
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-backup-repository.databaseConnection.deleteSuccess'),
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
