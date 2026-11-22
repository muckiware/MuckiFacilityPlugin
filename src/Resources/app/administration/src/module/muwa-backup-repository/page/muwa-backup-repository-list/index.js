import template from './muwa-backup-repository-list.html.twig';
import './muwa-backup-repository-list.scss';
import { getNextScheduledRun } from '../../helper/format-schedule-date';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

const CHECK_STATUS_OK = 'no errors were found';

Component.register('muwa-backup-repository-list', {

    template,

    inject: [
        'repositoryFactory',
        'acl'
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
            limit: 25,
            showAddModal: false,
            showDeleteModal: false
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
                    allowResize: true,
                    primary: true
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
                    property: 'lastBackupAt',
                    dataIndex: 'lastBackupAt',
                    label: this.$t('muwa-backup-repository.list.column-last-backup-at'),
                    allowResize: true,
                    sortable: false
                }, {
                    property: 'nextScheduledBackup',
                    dataIndex: 'nextScheduledBackup',
                    label: this.$t('muwa-backup-repository.list.column-next-scheduled-backup'),
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

        getLatestStats(item) {
            if (!item.backupRepositoryStats || item.backupRepositoryStats.length === 0) {
                return null;
            }

            return item.backupRepositoryStats[0];
        },

        formatNextScheduledBackup(item) {
            // an inactive repository never runs scheduled
            const nextRun = item.active ? getNextScheduledRun(item.schedule) : null;
            if (nextRun === null) {
                return this.$t('muwa-backup-repository.schedule.noActiveSchedule');
            }
            return this.dateFilter(nextRun, { hour: '2-digit', minute: '2-digit' });
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
            criteria.addAssociation('schedule');
            criteria.getAssociation('backupRepositoryChecks').addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.getAssociation('backupRepositoryStats')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);

            this.repository.search(criteria, Shopware.Context.api).then((response) => {

                this.backupRepository = response;
                this.total = response.total;
                this.isLoading = false;
            });
        },
        updateTotal({ total }) {
            this.total = total;
        },

        onDelete(id) {
            this.showDeleteModal = id;
        },

        onCloseDeleteModal() {
            this.showDeleteModal = false;
        },

        onConfirmDelete(item) {
            this.showDeleteModal = false;

            this.repository.delete(item.id, Shopware.Context.api).then(() => {
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
