import template from './muwa-restore-directory-tree.html.twig';
import './muwa-restore-directory-tree.scss';

const { Mixin } = Shopware;

const SNIPPET_PREFIX = 'muwa-backup-repository.restoreDirectory';

/**
 * Read-only tree of the restore directory with token based dump download.
 * Usage: <muwa-restore-directory-tree :backup-repository-id="id" />
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        backupRepositoryId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            httpClient: null,
            requestDirectory: '/_action/muwa/restore/directory',
            requestDownloadPrepare: '/_action/muwa/restore/download/prepare',
            // relative path => { expanded, loading, loaded, state, directories, files, truncated }
            nodes: {},
            downloadingPath: null,
            loadGeneration: 0,
        };
    },

    computed: {
        root() {
            return this.nodes[''] ?? null;
        },

        rootState() {
            return this.root?.state ?? null;
        },

        rows() {
            const rows = [];
            if (this.rootState !== 'ok') {
                return rows;
            }
            this.collectRows('', 0, rows);

            return rows;
        },

        rootMessageSnippet() {
            const snippets = {
                notConfigured: 'notConfigured',
                notAllowed: 'notAllowed',
                empty: 'emptyState',
                error: 'rootLoadError',
            };

            return snippets[this.rootState] ? `${SNIPPET_PREFIX}.${snippets[this.rootState]}` : null;
        },
    },

    watch: {
        backupRepositoryId() {
            this.refresh();
        },
    },

    created() {
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.loadNode('');
    },

    methods: {
        getApiHeader() {
            return {
                Accept: 'application/json',
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
                'Content-Type': 'application/json',
            };
        },

        refresh() {
            this.loadGeneration += 1;
            this.nodes = {};
            return this.loadNode('');
        },

        setNode(path, node) {
            this.nodes = { ...this.nodes, [path]: node };
        },

        async loadNode(path) {
            const generation = this.loadGeneration;
            const previous = this.nodes[path] ?? {};
            this.setNode(path, { ...previous, expanded: true, loading: true, loaded: false, directories: [], files: [], truncated: false, state: null });

            try {
                const response = await this.httpClient.post(
                    this.requestDirectory,
                    { backupRepositoryId: this.backupRepositoryId, path },
                    { headers: this.getApiHeader() },
                );
                if (generation !== this.loadGeneration) {
                    return;
                }
                const data = response.data ?? {};
                this.setNode(path, {
                    expanded: true,
                    loading: false,
                    loaded: true,
                    state: data.state ?? 'ok',
                    directories: data.directories ?? [],
                    files: data.files ?? [],
                    truncated: !!data.truncated,
                });
            } catch (exception) {
                if (generation !== this.loadGeneration) {
                    return;
                }
                this.setNode(path, { expanded: false, loading: false, loaded: false, state: path === '' ? 'error' : null, directories: [], files: [], truncated: false });
                this.createNotificationError({
                    title: this.$tc(`${SNIPPET_PREFIX}.errorTitle`),
                    message: this.$tc(`${SNIPPET_PREFIX}.loadError`),
                });
            }
        },

        collectRows(path, depth, rows) {
            const node = this.nodes[path];
            if (!node || !node.loaded) {
                return;
            }

            node.directories.forEach((directory) => {
                const child = this.nodes[directory.path];
                rows.push({ type: 'directory', key: `d:${directory.path}`, depth, entry: directory, node: child ?? null });
                if (child?.expanded) {
                    if (child.loading) {
                        rows.push({ type: 'loading', key: `l:${directory.path}`, depth: depth + 1 });
                    }
                    this.collectRows(directory.path, depth + 1, rows);
                }
            });
            node.files.forEach((file) => {
                rows.push({ type: 'file', key: `f:${file.path}`, depth, entry: file });
            });
            if (node.truncated) {
                rows.push({ type: 'truncated', key: `t:${path}`, depth });
            }
        },

        onToggleDirectory(directory) {
            const node = this.nodes[directory.path];
            if (!node) {
                this.loadNode(directory.path);
                return;
            }
            if (node.loading) {
                return;
            }
            if (!node.loaded) {
                this.loadNode(directory.path);
                return;
            }
            this.setNode(directory.path, { ...node, expanded: !node.expanded });
        },

        async onDownload(file) {
            if (this.downloadingPath) {
                return;
            }
            this.downloadingPath = file.path;

            try {
                const response = await this.httpClient.post(
                    this.requestDownloadPrepare,
                    { backupRepositoryId: this.backupRepositoryId, path: file.path },
                    { headers: this.getApiHeader() },
                );
                const accessToken = response.data?.accessToken;
                if (!accessToken) {
                    throw new Error('Missing access token');
                }
                window.location.href = `${Shopware.Context.api.apiPath}/_action/muwa/restore/download?accessToken=${encodeURIComponent(accessToken)}`;
                this.createNotificationSuccess({
                    title: this.$tc('global.default.success'),
                    message: this.$tc(`${SNIPPET_PREFIX}.downloadStarted`),
                });
            } catch (exception) {
                this.createNotificationError({
                    title: this.$tc(`${SNIPPET_PREFIX}.errorTitle`),
                    message: this.$tc(`${SNIPPET_PREFIX}.downloadError`),
                });
            } finally {
                this.downloadingPath = null;
            }
        },

        formatSize(bytes) {
            const size = Number(bytes);
            if (!Number.isFinite(size) || size < 0) {
                return '';
            }
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let value = size;
            let unit = 0;
            while (value >= 1024 && unit < units.length - 1) {
                value /= 1024;
                unit += 1;
            }

            return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
        },

        formatDate(value) {
            return value ? Shopware.Utils.format.date(value) : '';
        },
    },
};
