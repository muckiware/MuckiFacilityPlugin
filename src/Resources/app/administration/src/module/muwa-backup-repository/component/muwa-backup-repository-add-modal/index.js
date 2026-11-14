import template from './muwa-backup-repository-add-modal.html.twig';
import './muwa-backup-repository-add-modal.scss';

const { Mixin } = Shopware;

/**
 * Modal behind the "create repository" button: choose between a new, empty repository
 * (existing create page) and taking over an existing local restic repository.
 *
 * The password is only kept in component data. The component is destroyed via v-if of
 * the parent on close, so nothing has to be reset. It must never be logged.
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['feature'],

    emits: ['modal-close'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            V6_5_0_0: false,
            V6_6_0_0: false,
            V6_7_0_0: false,
            step: 'choice',
            repositoryPath: '',
            repositoryPassword: '',
            isLoading: false,
            errorMessage: null,
            httpClient: null,
            requestTakeover: '/_action/muwa/backup/repository/takeover'
        };
    },

    computed: {

        isV6600() {
            return this.V6_6_0_0;
        },
        isV6500() {
            return this.V6_5_0_0;
        },
        isV6700() {
            return this.V6_7_0_0;
        },

        isSubmitDisabled() {
            return this.repositoryPath.trim() === '' || this.repositoryPassword === '';
        }
    },

    created() {

        if (this.feature.isActive('V6_6_0_0')) {
            this.V6_6_0_0 = true;
        }

        if (this.feature.isActive('V6_5_0_0') && !this.feature.isActive('V6_6_0_0')) {
            this.V6_5_0_0 = true;
        }

        if (this.feature.isActive('V6_7_0_0')) {
            this.V6_7_0_0 = true;
        }

        this.httpClient = Shopware.Application.getContainer('init').httpClient;
    },

    methods: {

        onClose() {
            if (this.isLoading) {
                return;
            }
            this.$emit('modal-close');
        },

        onChooseNew() {
            this.$emit('modal-close');
            this.$router.push({ name: 'muwa.backup.repository.create' });
        },

        onChooseTakeover() {
            this.step = 'takeover';
        },

        onBack() {
            this.errorMessage = null;
            this.step = 'choice';
        },

        async onSubmit() {
            if (this.isLoading || this.isSubmitDisabled) {
                return;
            }

            this.isLoading = true;
            this.errorMessage = null;

            try {
                const response = await this.httpClient.post(
                    this.requestTakeover,
                    {
                        id: Shopware.Utils.createId(),
                        repositoryPath: this.repositoryPath,
                        repositoryPassword: this.repositoryPassword
                    },
                    { headers: this.getApiHeader() }
                );

                this.createNotificationSuccess({
                    title: this.$t('muwa-backup-repository.takeover.successTitle'),
                    message: this.$t('muwa-backup-repository.takeover.successMessage', { count: response.data.snapshotsCount })
                });
                this.isLoading = false;
                this.$emit('modal-close');
                this.$router.push({
                    name: 'muwa.backup.repository.detail',
                    params: { id: response.data.id, tab: 'backupRepositoryConfig' }
                });
            } catch (exception) {
                this.isLoading = false;
                this.errorMessage = this.getErrorMessage(exception);
            }
        },

        /**
         * Maps the API error code to a snippet (meta values as placeholders); falls back to
         * the server detail, and to a generic text when there is no response at all.
         */
        getErrorMessage(exception) {
            const error = exception?.response?.data?.errors?.[0];

            if (!error) {
                return this.$t('muwa-backup-repository.takeover.errors.unknown');
            }

            const key = `muwa-backup-repository.takeover.errors.${error.code}`;
            if (error.code && this.$te(key)) {
                return this.$t(key, error.meta ?? {});
            }

            return error.detail || this.$t('muwa-backup-repository.takeover.errors.unknown');
        },

        getApiHeader() {
            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
                'Content-Type': 'application/json'
            };
        }
    }
};
