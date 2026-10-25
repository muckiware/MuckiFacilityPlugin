const { Component } = Shopware;

const ALLOWED_TAGS = ['a', 'b', 'i', 'u', 'strong', 'em', 'br'];
const ALLOWED_ATTR = ['href', 'target'];

function escapeHtml(value) {
    const replacements = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    };

    return String(value).replace(/[&<>"']/g, (character) => replacements[character]);
}

function translateBackupNotification(notification) {
    if (typeof notification.message !== 'string') {
        return null;
    }

    let payload;
    try {
        payload = JSON.parse(notification.message);
    } catch {
        return null;
    }

    if (payload.source !== 'mucki-facility' || typeof payload.snippet !== 'string') {
        return null;
    }

    const parameters = Object.fromEntries(
        Object.entries(payload.parameters ?? {}).map(([key, value]) => [key, escapeHtml(value)]),
    );
    const message = this.$t(payload.snippet, parameters);

    return this.$sanitize(message, {
        ALLOWED_TAGS,
        ALLOWED_ATTR,
    });
}

function createNotificationTranslationOverride() {
    return {
        methods: {
            getTranslatedMessage(notification) {
                const translatedMessage = translateBackupNotification.call(this, notification);
                if (translatedMessage !== null) {
                    return translatedMessage;
                }

                return this.$super('getTranslatedMessage', notification);
            },
        },
    };
}

Component.override('sw-notification-center-item', createNotificationTranslationOverride());
Component.override('sw-notifications', createNotificationTranslationOverride());
