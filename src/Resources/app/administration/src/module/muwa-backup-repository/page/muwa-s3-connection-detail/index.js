import template from './muwa-s3-connection-detail.html.twig';

const { Mixin } = Shopware;
const { createId } = Shopware.Utils;

const PROVIDERS = ['aws', 'minio', 'ceph', 'other'];
const SECRET_SOURCES = ['encrypted', 'env', 'file'];

function createEmptyConnection() {
    return {
        name: '',
        provider: 'aws',
        endpoint: '',
        region: '',
        accessKeyId: '',
        secretSource: 'encrypted',
    };
}

/**
 * Create and detail page of an S3 connection (`isNew` = route without id).
 *
 * The secret access key is never delivered by the API. It lives in its own data property, is sent
 * only to the action routes and stays empty on the detail page: saving it empty keeps the stored one.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['repositoryFactory', 'feature', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            V6_5_0_0: false,
            V6_6_0_0: false,
            V6_7_0_0: false,
            newId: createId(),
            connection: createEmptyConnection(),
            secretAccessKey: '',
            testBucket: '',
            isLoading: false,
            isTesting: false,
            processSuccess: false,
            httpClient: null,
            requestSave: '/_action/muwa/s3-connection/save',
            requestTest: '/_action/muwa/s3-connection/test',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        isV6500() {
            return this.V6_5_0_0;
        },

        isV6600() {
            return this.V6_6_0_0;
        },

        isV6700() {
            return this.V6_7_0_0;
        },

        isNew() {
            return !this.$route.params.id;
        },

        connectionId() {
            return this.isNew ? this.newId : this.$route.params.id;
        },

        canEdit() {
            return this.isNew
                ? this.acl.can('muwa_backup_repository.creator')
                : this.acl.can('muwa_backup_repository.editor');
        },

        isRegionRequired() {
            return this.connection.provider === 'aws';
        },

        isEndpointRequired() {
            return this.connection.provider !== 'aws';
        },

        /**
         * Both key sets on purpose: sw-select-field-deprecated (6.6) reads `id`/`name`,
         * mt-select (6.7) reads `value`/`label`.
         */
        providerOptions() {
            return PROVIDERS.map((provider) => this.createOption(provider, this.getProviderLabel(provider)));
        },

        secretSourceOptions() {
            return SECRET_SOURCES.map((source) => this.createOption(
                source,
                this.$t(`muwa-backup-repository.s3Connection.secretSources.${source}`)
            ));
        },

        isEncryptedSource() {
            return this.connection.secretSource === 'encrypted';
        },

        secretLabel() {
            return this.$t(`muwa-backup-repository.s3Connection.secretValueLabels.${this.connection.secretSource}`);
        },

        secretPlaceholder() {
            return this.isNew ? '' : this.$t('muwa-backup-repository.s3Connection.secretKeepPlaceholder');
        },
    },

    watch: {
        '$route.params.id'() {
            this.loadConnection();
        },

        // A typed secret must never show up in the plain env/file reference field.
        'connection.secretSource'() {
            this.secretAccessKey = '';
        },
    },

    created() {
        this.V6_6_0_0 = this.feature.isActive('V6_6_0_0');
        this.V6_5_0_0 = this.feature.isActive('V6_5_0_0') && !this.V6_6_0_0;
        this.V6_7_0_0 = this.feature.isActive('V6_7_0_0');
        this.httpClient = Shopware.Application.getContainer('init').httpClient;

        this.loadConnection();
    },

    methods: {
        createOption(value, label) {
            return { id: value, name: label, value, label };
        },

        getProviderLabel(provider) {
            const key = `muwa-backup-repository.s3Connection.providers.${provider}`;
            const label = this.$t(key);

            return label === key ? provider : label;
        },

        loadConnection() {
            this.secretAccessKey = '';
            this.testBucket = '';

            if (this.isNew) {
                this.connection = createEmptyConnection();
                return;
            }

            this.isLoading = true;
            this.repositoryFactory.create('muwa_s3_connection')
                .get(this.$route.params.id, Shopware.Context.api)
                .then((entity) => {
                    if (!entity) {
                        return;
                    }
                    // copy only the public fields; the secret is not part of the API response
                    this.connection = {
                        name: entity.name,
                        provider: entity.provider,
                        endpoint: entity.endpoint || '',
                        region: entity.region || '',
                        accessKeyId: entity.accessKeyId,
                        secretSource: entity.secretSource || 'encrypted',
                    };
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        buildPayload() {
            return {
                id: this.connectionId,
                name: this.connection.name,
                provider: this.connection.provider,
                endpoint: this.connection.endpoint,
                region: this.connection.region,
                accessKeyId: this.connection.accessKeyId,
                secretSource: this.connection.secretSource,
                secretAccessKey: this.secretAccessKey,
            };
        },

        onClickSave() {
            this.isLoading = true;
            this.processSuccess = false;

            this.httpClient.post(this.requestSave, this.buildPayload(), { headers: this.getApiHeader() }).then((response) => {
                this.processSuccess = true;
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-backup-repository.s3Connection.saveSuccess'),
                });

                if (this.isNew) {
                    this.$router.push({
                        name: 'muwa.backup.repository.s3ConnectionDetail',
                        params: { id: response.data.id },
                    });
                    return;
                }
                this.loadConnection();
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.s3Connection.saveError'),
                    message: this.getErrorMessage(exception),
                });
            }).finally(() => {
                this.isLoading = false;
            });
        },

        onClickTestConnection() {
            this.isTesting = true;
            const payload = this.buildPayload();
            if (this.isNew) {
                delete payload.id;
            }
            if (this.testBucket !== '') {
                payload.bucket = this.testBucket;
            }

            this.httpClient.post(this.requestTest, payload, { headers: this.getApiHeader() }).then((response) => {
                if (response.data?.success) {
                    this.createNotificationSuccess({
                        title: this.$t('muwa-backup-repository.s3Connection.testSuccessTitle'),
                        message: this.$t('muwa-backup-repository.s3Connection.testSuccessMessage'),
                    });
                    return;
                }
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.s3Connection.testErrorTitle'),
                    message: response.data?.message || '',
                });
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.s3Connection.testErrorTitle'),
                    message: this.getErrorMessage(exception),
                });
            }).finally(() => {
                this.isTesting = false;
            });
        },

        saveFinish() {
            this.processSuccess = false;
        },

        getErrorMessage(exception) {
            const detail = exception?.response?.data?.errors?.[0]?.detail;

            return detail || exception?.message || String(exception);
        },

        getApiHeader() {
            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                'Content-Type': 'application/json',
            };
        },
    },
};
