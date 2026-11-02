/**
 * Backup notifications are stored server side as a JSON payload (snippet key + parameters), because
 * the server does not know the administration language of the reading user. They are translated
 * here, right after the notification worker fetched them.
 *
 * `notificationsService` and its response are identical in Shopware 6.6 and 6.7, while the
 * notification components differ (6.7 renders through the `notification-translation` mixin, 6.6
 * sanitizes `notification.message` in the template). Both sanitize the message themselves, so only
 * the parameters are escaped here.
 */
const { Application } = Shopware;

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

function translateBackupNotification(message) {
    if (typeof message !== 'string') {
        return null;
    }

    let payload;
    try {
        payload = JSON.parse(message);
    } catch {
        return null;
    }

    if (payload?.source !== 'mucki-facility' || typeof payload.snippet !== 'string') {
        return null;
    }

    const snippet = Shopware.Snippet;
    if (!snippet) {
        return null;
    }

    const parameters = Object.fromEntries(
        Object.entries(payload.parameters ?? {}).map(([key, value]) => [key, escapeHtml(value)]),
    );

    return snippet.t(payload.snippet, parameters);
}

function translateNotifications(data) {
    if (!Array.isArray(data?.notifications)) {
        return data;
    }

    return {
        ...data,
        notifications: data.notifications.map((notification) => ({
            ...notification,
            message: translateBackupNotification(notification.message) ?? notification.message,
        })),
    };
}

Application.addServiceProviderDecorator('notificationsService', (service) => {
    const fetchNotifications = service.fetchNotifications.bind(service);

    service.fetchNotifications = (...args) => fetchNotifications(...args).then(translateNotifications);

    return service;
});
