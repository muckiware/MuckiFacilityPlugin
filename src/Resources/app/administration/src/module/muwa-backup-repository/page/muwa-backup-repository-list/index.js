import template from './muwa-backup-repository-list.html.twig';
import './muwa-backup-repository-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

const CHECK_STATUS_OK = 'no errors were found';

Component.register('muwa-backup-repository-list', {

    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing')
    ],

    data() {

        return {
            backupRepository: null,
            isLoading: true,
            total: 0,
            limit: 25
        };
    },

    metaInfo() {

        return {
            title: this.$createTitle()
        };
    },

    computed: {

        columns() {
            return this.getColumns();
        },

        repository() {
            return this.getRepository();
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        tab() {
            return this.$route.params.tab || 'repositoryList';
        },
    },

    methods: {

        getColumns() {
            return [
                {
                    property: 'name',
                    dataIndex: 'name',
                    label: this.$t('muwa-backup-repository.list.column-internal-name'),
                    allowResize: true
                }, {
                    property: 'active',
                    dataIndex: 'active',
                    label: this.$t('muwa-backup-repository.list.column-active'),
                    allowResize: true
                }, {
                    property: 'checkStatus',
                    dataIndex: 'checkStatus',
                    label: this.$t('muwa-backup-repository.list.column-check-status'),
                    allowResize: true,
                    sortable: false
                }, {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: this.$t('muwa-backup-repository.list.column-created-at'),
                    allowResize: true
                }, {
                    property: 'updatedAt',
                    dataIndex: 'updatedAt',
                    label: this.$t('muwa-backup-repository.list.column-updated-at'),
                    allowResize: true
                }];
        },

        getLatestCheck(item) {
            if (!item.backupRepositoryChecks || item.backupRepositoryChecks.length === 0) {
                return null;
            }

            return item.backupRepositoryChecks[0];
        },

        isCheckStatusOk(item) {
            const latestCheck = this.getLatestCheck(item);

            return latestCheck !== null && latestCheck.checkStatus === CHECK_STATUS_OK;
        },

        getRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        getList() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.setTerm(this.term);
            criteria.addAssociation('backupRepositoryChecks');
            criteria.getAssociation('backupRepositoryChecks').addSorting(Criteria.sort('createdAt', 'DESC'));

            this.repository.search(criteria, Shopware.Context.api).then((response) => {

                this.backupRepository = response;
                this.total = response.total;
                this.isLoading = false;
            });
        },
        updateTotal({ total }) {
            this.total = total;
        },

        deleteBackupRepository(item) {

            let that = this;
            that.repository.delete(item.id, Shopware.Context.api).then(() => {
                this.getList();
            });
        }
    },

    created() {

        if(this.$route.params.tab === undefined) {
            this.$router.push({ name: 'muwa.backup.repository.index', params: { tab: 'repositoryList' } });
        }
    }
});
