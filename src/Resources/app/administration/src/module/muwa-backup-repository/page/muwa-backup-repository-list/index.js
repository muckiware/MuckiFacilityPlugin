import template from './muwa-backup-repository-list.html.twig';
import './muwa-backup-repository-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

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
                    label: this.$t('lightson-pseudo-product.list.column-internal-name'),
                    allowResize: true
                }, {
                    property: 'active',
                    dataIndex: 'active',
                    label: this.$t('lightson-pseudo-product.list.column-active'),
                    allowResize: true
                }, {
                    property: 'createdAt',
                    dataIndex: 'createdAt',
                    label: this.$t('lightson-pseudo-product.list.column-created-at'),
                    allowResize: true
                }, {
                    property: 'updatedAt',
                    dataIndex: 'updatedAt',
                    label: this.$t('lightson-pseudo-product.list.column-updated-at'),
                    allowResize: true
                }];
        },

        getRepository() {
            return this.repositoryFactory.create('muwa_backup_repository');
        },

        getList() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.setTerm(this.term);

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
        },
    },

    created() {

        if(this.$route.params.tab === undefined) {
            this.$router.push({ name: 'muwa.search.structure.index', params: { tab: 'repositoryList' } });
        }
    }
});
