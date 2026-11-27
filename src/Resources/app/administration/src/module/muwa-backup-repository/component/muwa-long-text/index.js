import template from './muwa-long-text.html.twig';
import './muwa-long-text.scss';

const { Component } = Shopware;

/**
 * Default length (characters) above which a text counts as "long". Shorter texts without a line
 * break fit into a grid cell and are shown as they are, without the "show more" button.
 */
const DEFAULT_MAX_LENGTH = 80;

/**
 * Shows a (possibly long) text such as a restic check status or an S3 mirror message. A long
 * text is shortened and gets a "show more" button that opens the full text in a modal.
 * With `show-text="false"` only the button is rendered (e.g. next to a status label).
 *
 * Usage: <muwa-long-text :text="item.checkStatus" :title="$t('...')" />
 */
Component.register('muwa-long-text', {

    template,

    compatConfig: Shopware.compatConfig,

    props: {
        text: {
            type: String,
            required: false,
            default: '',
        },
        title: {
            type: String,
            required: false,
            default: '',
        },
        maxLength: {
            type: Number,
            required: false,
            default: DEFAULT_MAX_LENGTH,
        },
        showText: {
            type: Boolean,
            required: false,
            default: true,
        },
    },

    data() {
        return {
            showModal: false,
        };
    },

    computed: {
        normalizedText() {
            return typeof this.text === 'string' ? this.text.trim() : '';
        },

        isLong() {
            return this.normalizedText.length > this.maxLength || this.normalizedText.includes('\n');
        },

        shortText() {
            if (!this.isLong) {
                return this.normalizedText;
            }
            const firstLine = this.normalizedText.split('\n')[0];
            if (firstLine.length <= this.maxLength) {
                return `${firstLine} …`;
            }
            return `${firstLine.slice(0, this.maxLength).trimEnd()} …`;
        },
    },

    methods: {
        onOpenModal() {
            this.showModal = true;
        },

        onCloseModal() {
            this.showModal = false;
        },
    },
});
