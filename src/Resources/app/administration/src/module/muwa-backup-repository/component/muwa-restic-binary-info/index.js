import template from './muwa-restic-binary-info.html.twig';
import './muwa-restic-binary-info.scss';

const { Component } = Shopware;

/**
 * Text line on the plugin configuration page with path and version of the restic binary the
 * plugin uses. Embedded via `<component>` in config.xml; the bound config value is not used.
 * The server answers for the saved configuration, not for unsaved form input.
 */
Component.register('muwa-restic-binary-info', {

    template,

    compatConfig: Shopware.compatConfig,

    data() {
        return {
            isLoading: true,
            info: null,
            hasLoadError: false,
            requestResticInfo: '/_action/muwa/restic/info',
        };
    },

    computed: {
        isUnsupported() {
            return this.info?.version !== null && this.info?.supported === false;
        },

        hasWarning() {
            return this.hasLoadError || this.info?.error !== null || this.isUnsupported;
        },
    },

    created() {
        this.loadInfo();
    },

    methods: {
        loadInfo() {
            const httpClient = Shopware.Application.getContainer('init').httpClient;
            this.isLoading = true;
            this.hasLoadError = false;

            httpClient.get(this.requestResticInfo, { headers: this.getApiHeader() }).then((response) => {
                this.info = response.data;
            }).catch(() => {
                this.info = null;
                this.hasLoadError = true;
            }).finally(() => {
                this.isLoading = false;
            });
        },

        getApiHeader() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
            };
        },
    },
});
