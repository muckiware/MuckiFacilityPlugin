import template from './muwa-s3-connection-select.html.twig';

const { Criteria } = Shopware.Data;

/**
 * Internal option value of "no mirroring". Neither select variant can carry `null` as an
 * option value (the native select of 6.6 reads it back as text, mt-select treats it as "empty"),
 * so the sentinel lives only inside this component. Outward "no mirroring" is `null`.
 */
const NO_MIRROR_OPTION = '__none__';

/**
 * Select for the S3 mirror connection of a backup configuration.
 * Usage: <muwa-s3-connection-select v-model:value="backupRepository.mirrorS3ConnectionId" />
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'feature'],

    emits: ['update:value'],

    props: {
        value: {
            type: [String, null],
            required: false,
            default: null,
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            V6_6_0_0: false,
            V6_7_0_0: false,
            connections: [],
        };
    },

    computed: {
        isV6600() {
            return this.V6_6_0_0;
        },

        isV6700() {
            return this.V6_7_0_0;
        },

        /**
         * Both key sets on purpose: sw-select-field-deprecated (6.6) reads `id`/`name`,
         * mt-select (6.7) reads `value`/`label`.
         */
        connectionOptions() {
            const noMirrorLabel = this.$t('muwa-backup-repository.s3Connection.noMirroring');
            const options = [
                { id: NO_MIRROR_OPTION, name: noMirrorLabel, value: NO_MIRROR_OPTION, label: noMirrorLabel },
            ];

            return options.concat(this.connections.map((connection) => {
                const label = connection.endpoint
                    ? `${connection.name} (${connection.endpoint})`
                    : connection.name;

                return { id: connection.id, name: label, value: connection.id, label };
            }));
        },

        selectedValue: {
            get() {
                return this.value ?? NO_MIRROR_OPTION;
            },
            set(newValue) {
                this.$emit('update:value', newValue === NO_MIRROR_OPTION ? null : newValue);
            },
        },
    },

    created() {
        this.V6_6_0_0 = this.feature.isActive('V6_6_0_0');
        this.V6_7_0_0 = this.feature.isActive('V6_7_0_0');

        this.loadConnections();
    },

    methods: {
        loadConnections() {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            this.repositoryFactory.create('muwa_s3_connection')
                .search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.connections = Array.from(result);
                })
                .catch(() => {
                    // without read access only "no mirroring" is offered
                    this.connections = [];
                });
        },
    },
};
