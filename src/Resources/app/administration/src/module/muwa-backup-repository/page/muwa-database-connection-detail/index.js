import template from './muwa-database-connection-detail.html.twig';

const { Mixin } = Shopware;
const { createId } = Shopware.Utils;

const DEFAULT_PORTS = { mysql: 3306 };
const KNOWN_TYPES = ['mysql'];
const PASSWORD_SOURCES = ['encrypted', 'env', 'file'];

function createEmptyConnection() {
    return {
        name: '',
        type: 'mysql',
        host: '',
        port: DEFAULT_PORTS.mysql,
        databaseName: '',
        username: '',
        passwordSource: 'encrypted',
    };
}

/**
 * Create and detail page of a database connection (`isNew` = route without id).
 *
 * The password is never delivered by the API. It lives in its own data property, is sent only to
 * the action routes and stays empty on the detail page: saving it empty keeps the stored one.
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
            password: '',
            passwordRepeat: '',
            isLoading: false,
            isTesting: false,
            processSuccess: false,
            httpClient: null,
            requestSave: '/_action/muwa/database-connection/save',
            requestTest: '/_action/muwa/database-connection/test',
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

        /**
         * Both key sets on purpose: sw-select-field-deprecated (6.6) reads `id`/`name`,
         * mt-select (6.7) reads `value`/`label`. A type registered by another plugin stays selectable.
         */
        typeOptions() {
            const types = [...KNOWN_TYPES];
            if (this.connection.type && !types.includes(this.connection.type)) {
                types.push(this.connection.type);
            }

            return types.map((type) => this.createOption(type, this.getTypeLabel(type)));
        },

        passwordSourceOptions() {
            return PASSWORD_SOURCES.map((source) => this.createOption(
                source,
                this.$t(`muwa-backup-repository.databaseConnection.passwordSources.${source}`)
            ));
        },

        isEncryptedSource() {
            return this.connection.passwordSource === 'encrypted';
        },

        passwordLabel() {
            return this.$t(`muwa-backup-repository.databaseConnection.passwordValueLabels.${this.connection.passwordSource}`);
        },

        passwordPlaceholder() {
            return this.isNew ? '' : this.$t('muwa-backup-repository.databaseConnection.passwordKeepPlaceholder');
        },
    },

    watch: {
        '$route.params.id'() {
            this.loadConnection();
        },

        // A typed password must never show up in the plain env/file reference field.
        'connection.passwordSource'() {
            this.password = '';
            this.passwordRepeat = '';
        },

        'connection.type'(type) {
            if (!this.connection.port && DEFAULT_PORTS[type]) {
                this.connection.port = DEFAULT_PORTS[type];
            }
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

        getTypeLabel(type) {
            const key = `muwa-backup-repository.databaseConnection.types.${type}`;
            const label = this.$t(key);

            return label === key ? type : label;
        },

        loadConnection() {
            this.password = '';
            this.passwordRepeat = '';

            if (this.isNew) {
                this.connection = createEmptyConnection();
                return;
            }

            this.isLoading = true;
            this.repositoryFactory.create('muwa_database_connection')
                .get(this.$route.params.id, Shopware.Context.api)
                .then((entity) => {
                    if (!entity) {
                        return;
                    }
                    // copy only the public fields; the password is not part of the API response
                    this.connection = {
                        name: entity.name,
                        type: entity.type,
                        host: entity.host,
                        port: entity.port,
                        databaseName: entity.databaseName,
                        username: entity.username,
                        passwordSource: entity.passwordSource || 'encrypted',
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
                type: this.connection.type,
                host: this.connection.host,
                port: this.connection.port,
                databaseName: this.connection.databaseName,
                username: this.connection.username,
                passwordSource: this.connection.passwordSource,
                password: this.password,
            };
        },

        hasPasswordErrors() {
            if (this.isEncryptedSource && this.password !== '' && this.password !== this.passwordRepeat) {
                this.createNotificationError({
                    title: this.$t('global.default.error'),
                    message: this.$t('muwa-backup-repository.databaseConnection.passwordMismatch'),
                });
                return true;
            }

            return false;
        },

        onClickSave() {
            if (this.hasPasswordErrors()) {
                return;
            }

            this.isLoading = true;
            this.processSuccess = false;

            this.httpClient.post(this.requestSave, this.buildPayload(), { headers: this.getApiHeader() }).then((response) => {
                this.processSuccess = true;
                this.createNotificationSuccess({
                    title: this.$t('global.default.success'),
                    message: this.$t('muwa-backup-repository.databaseConnection.saveSuccess'),
                });

                if (this.isNew) {
                    this.$router.push({
                        name: 'muwa.backup.repository.databaseConnectionDetail',
                        params: { id: response.data.id },
                    });
                    return;
                }
                this.loadConnection();
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.databaseConnection.saveError'),
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

            this.httpClient.post(this.requestTest, payload, { headers: this.getApiHeader() }).then((response) => {
                if (response.data?.success) {
                    this.createNotificationSuccess({
                        title: this.$t('muwa-backup-repository.databaseConnection.testSuccessTitle'),
                        message: this.$t('muwa-backup-repository.databaseConnection.testSuccessMessage'),
                    });
                    return;
                }
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.databaseConnection.testErrorTitle'),
                    message: response.data?.message || '',
                });
            }).catch((exception) => {
                this.createNotificationError({
                    title: this.$t('muwa-backup-repository.databaseConnection.testErrorTitle'),
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
