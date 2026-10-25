import template from './muwa-backup-repository-path-field.html.twig';
import './muwa-backup-repository-path-field.scss';

const { debounce } = Shopware.Utils;

const DROPDOWN_GAP = 4;
const DROPDOWN_MAX_HEIGHT = 360;
const DROPDOWN_MIN_HEIGHT = 160;
const VIEWPORT_MARGIN = 16;

/**
 * Text field for server-side paths with a read-only folder browser dropdown.
 * Usage: <muwa-backup-repository-path-field v-model:value="..." />
 */
export default {
    template,

    compatConfig: Shopware.compatConfig,

    inject: ['acl'],

    emits: ['update:value'],

    props: {
        value: {
            type: [String, null],
            required: false,
            default: null,
        },
        label: {
            type: String,
            required: false,
            default: null,
        },
        placeholder: {
            type: String,
            required: false,
            default: '',
        },
        helpText: {
            type: String,
            required: false,
            default: null,
        },
        required: {
            type: Boolean,
            required: false,
            default: false,
        },
        maxlength: {
            type: [String, Number],
            required: false,
            default: null,
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        name: {
            type: String,
            required: false,
            default: null,
        },
        checkDumpPath: {
            type: Boolean,
            required: false,
            default: false,
        },
        selectFile: {
            type: Boolean,
            required: false,
            default: false,
        },
        repositoryPath: {
            type: [String, null],
            required: false,
            default: null,
        },
        restorePath: {
            type: [String, null],
            required: false,
            default: null,
        },
    },

    data() {
        return {
            httpClient: null,
            requestDirectories: '/_action/muwa/filesystem/directories',
            requestFiles: '/_action/muwa/filesystem/files',
            requestCheckDumpPath: '/_action/muwa/filesystem/check-dump-path',
            isOpen: false,
            isLoading: false,
            listing: null,
            loadError: null,
            loadErrorDetail: null,
            listSequence: 0,
            checkSequence: 0,
            dumpPathResult: null,
            dropdownStyle: {},
        };
    },

    computed: {
        canBrowse() {
            return !this.disabled && this.acl.can('muwa_backup_repository.editor');
        },

        canCheckDumpPath() {
            return this.checkDumpPath && this.acl.can('muwa_backup_repository.editor');
        },

        pathSegments() {
            if (!this.listing || !this.listing.path) {
                return [];
            }

            const segments = [{ label: '/', path: '/' }];
            let current = '';
            this.listing.path.split('/').filter(part => part !== '').forEach((part) => {
                current = `${current}/${part}`;
                segments.push({ label: part, path: current });
            });

            return segments;
        },

        dumpPathWarning() {
            if (!this.dumpPathResult) {
                return null;
            }
            if (this.dumpPathResult.rejection) {
                return this.$t(`muwa-backup-repository.pathField.dumpPath.${this.dumpPathResult.rejection}`);
            }
            if (this.dumpPathResult.writable === false) {
                return this.$t('muwa-backup-repository.pathField.dumpPath.notWritable');
            }

            return null;
        },
    },

    watch: {
        value() {
            this.scheduleDumpPathCheck();
        },
        repositoryPath() {
            this.scheduleDumpPathCheck();
        },
        restorePath() {
            this.scheduleDumpPathCheck();
        },
        canBrowse(canBrowse) {
            if (!canBrowse) {
                this.closeDropdown();
            }
        },
    },

    created() {
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        // Created per instance: several fields (e.g. backup path grid rows) must not share one timer.
        this.debouncedLoadDirectories = debounce(this.loadDirectories, 300);
        this.debouncedCheckDumpPath = debounce(this.runDumpPathCheck, 400);
        this.runDumpPathCheck();
    },

    mounted() {
        document.addEventListener('mousedown', this.onDocumentMouseDown);
        document.addEventListener('keydown', this.onDocumentKeyDown);
    },

    // Vue 3 (6.7) calls beforeUnmount, the 6.6 compat build may still call beforeDestroy.
    beforeUnmount() {
        this.cleanup();
    },

    beforeDestroy() {
        this.cleanup();
    },

    methods: {
        cleanup() {
            document.removeEventListener('mousedown', this.onDocumentMouseDown);
            document.removeEventListener('keydown', this.onDocumentKeyDown);
            this.removePositionListeners();
            this.debouncedLoadDirectories.cancel();
            this.debouncedCheckDumpPath.cancel();
        },

        onInput(value) {
            this.$emit('update:value', value);
            if (this.isOpen) {
                this.listSequence += 1;
                this.debouncedLoadDirectories(value);
            }
        },

        openDropdown() {
            if (!this.canBrowse || this.isOpen) {
                return;
            }
            this.isOpen = true;
            this.updateDropdownPosition();
            window.addEventListener('scroll', this.updateDropdownPosition, true);
            window.addEventListener('resize', this.updateDropdownPosition);
            this.loadDirectories(this.value);
        },

        closeDropdown() {
            if (!this.isOpen) {
                return;
            }
            this.isOpen = false;
            this.listSequence += 1;
            this.isLoading = false;
            this.debouncedLoadDirectories.cancel();
            this.removePositionListeners();
        },

        removePositionListeners() {
            window.removeEventListener('scroll', this.updateDropdownPosition, true);
            window.removeEventListener('resize', this.updateDropdownPosition);
        },

        onDocumentMouseDown(event) {
            if (!this.isOpen) {
                return;
            }
            const inField = this.$refs.field && this.$refs.field.contains(event.target);
            const inDropdown = this.$refs.dropdown && this.$refs.dropdown.contains(event.target);
            if (!inField && !inDropdown) {
                this.closeDropdown();
            }
        },

        onFocusOut(event) {
            // relatedTarget is null for clicks on non-focusable areas; onDocumentMouseDown handles those.
            const next = event.relatedTarget;
            if (!this.isOpen || !next) {
                return;
            }
            const inField = this.$refs.field && this.$refs.field.contains(next);
            const inDropdown = this.$refs.dropdown && this.$refs.dropdown.contains(next);
            if (!inField && !inDropdown) {
                this.closeDropdown();
            }
        },

        onDocumentKeyDown(event) {
            if (this.isOpen && event.key === 'Escape') {
                this.closeDropdown();
            }
        },

        updateDropdownPosition() {
            const fieldBox = this.getFieldBox();
            if (!fieldBox) {
                return;
            }
            const rect = fieldBox.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom - DROPDOWN_GAP - VIEWPORT_MARGIN;
            const spaceAbove = rect.top - DROPDOWN_GAP - VIEWPORT_MARGIN;
            const openAbove = spaceBelow < DROPDOWN_MIN_HEIGHT && spaceAbove > spaceBelow;
            const maxHeight = Math.min(DROPDOWN_MAX_HEIGHT, openAbove ? spaceAbove : spaceBelow);

            this.dropdownStyle = {
                left: `${rect.left}px`,
                width: `${rect.width}px`,
                maxHeight: `${Math.max(maxHeight, 0)}px`,
                top: openAbove ? 'auto' : `${rect.bottom + DROPDOWN_GAP}px`,
                bottom: openAbove ? `${window.innerHeight - rect.top + DROPDOWN_GAP}px` : 'auto',
            };
        },

        getFieldBox() {
            const field = this.$refs.field;
            if (!field) {
                return null;
            }
            const input = field.querySelector('input');
            if (!input) {
                return field;
            }

            // The bordered box around input + suffix (6.7: mt-field, 6.6: sw-block-field).
            return input.closest('.mt-block-field__block, .sw-block-field__block') || input;
        },

        loadDirectories(path) {
            if (!this.isOpen) {
                return;
            }
            this.listSequence += 1;
            const sequence = this.listSequence;
            this.isLoading = true;
            this.loadError = null;
            this.loadErrorDetail = null;

            this.httpClient.post(
                this.selectFile ? this.requestFiles : this.requestDirectories,
                { path: path || null },
                { headers: this.getApiHeader() },
            ).then((response) => {
                if (sequence !== this.listSequence) {
                    return;
                }
                this.listing = response.data;
                this.isLoading = false;
                this.$nextTick(this.updateDropdownPosition);
            }).catch((exception) => {
                if (sequence !== this.listSequence) {
                    return;
                }
                this.loadError = this.getErrorMessage(exception);
                this.loadErrorDetail = this.getServerDetail(exception);
                this.isLoading = false;
            });
        },

        getFirstError(exception) {
            const errors = exception && exception.response && exception.response.data
                ? exception.response.data.errors
                : null;

            return Array.isArray(errors) && errors.length > 0 ? errors[0] : null;
        },

        getServerDetail(exception) {
            const error = this.getFirstError(exception);

            return error && error.detail ? error.detail : null;
        },

        getErrorMessage(exception) {
            const error = this.getFirstError(exception);
            if (error && error.code === 'MUWA_INVALID_PATH') {
                return this.$t('muwa-backup-repository.pathField.invalidPath');
            }

            return this.$t('muwa-backup-repository.pathField.loadError');
        },

        openDirectory(directory) {
            if (!directory.readable) {
                return;
            }
            this.loadDirectories(directory.path);
        },

        chooseFile(file) {
            if (!file || !file.readable) {
                return;
            }
            this.$emit('update:value', file.path);
            this.closeDropdown();
        },

        chooseFolder() {
            if (!this.listing || !this.listing.path) {
                return;
            }
            this.$emit('update:value', this.listing.path);
            this.closeDropdown();
        },

        scheduleDumpPathCheck() {
            if (!this.canCheckDumpPath) {
                return;
            }
            // Invalidate a request that is still in flight for the previous value right away.
            this.checkSequence += 1;
            this.debouncedCheckDumpPath();
        },

        runDumpPathCheck() {
            if (!this.canCheckDumpPath) {
                return;
            }
            this.checkSequence += 1;
            const sequence = this.checkSequence;

            this.httpClient.post(
                this.requestCheckDumpPath,
                {
                    dbDumpPath: this.value || null,
                    repositoryPath: this.repositoryPath || null,
                    restorePath: this.restorePath || null,
                },
                { headers: this.getApiHeader() },
            ).then((response) => {
                if (sequence === this.checkSequence) {
                    this.dumpPathResult = response.data;
                }
            }).catch(() => {
                if (sequence === this.checkSequence) {
                    this.dumpPathResult = null;
                }
            });
        },

        getApiHeader() {
            return {
                Accept: 'application/vnd.api+json',
                Authorization: `Bearer ${ Shopware.Context.api.authToken.access }`,
                'Content-Type': 'application/json',
            };
        },
    },
};
