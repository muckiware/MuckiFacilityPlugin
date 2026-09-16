/**
 * ACL-Mapping fuer das Backup-Repository-Modul.
 *
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'settings',
    key: 'muwa_backup_repository',
    roles: {
        viewer: {
            privileges: [
                'muwa_backup_repository:read',
                'muwa_backup_repository_checks:read',
                'muwa_backup_repository_snapshots:read',
                'muwa_backup_repository_stats:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'muwa_backup_repository:update',
                'muwa_backup_repository:backup',
                'muwa_backup_repository_checks:create',
                'muwa_backup_repository_snapshots:create',
                'muwa_backup_repository_snapshots:delete',
                'muwa_backup_repository_stats:create',
                'muwa_backup_repository_stats:delete',
            ],
            dependencies: [
                'muwa_backup_repository.viewer',
            ],
        },
        creator: {
            privileges: [
                'muwa_backup_repository:create',
            ],
            dependencies: [
                'muwa_backup_repository.viewer',
                'muwa_backup_repository.editor',
            ],
        },
        deleter: {
            privileges: [
                'muwa_backup_repository:delete',
            ],
            dependencies: [
                'muwa_backup_repository.viewer',
            ],
        },
    },
});

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: null,
    key: 'muwa_backup_repository',
    roles: {
        restore: {
            privileges: [
                'muwa_backup_repository:restore',
            ],
            dependencies: [
                'muwa_backup_repository.viewer',
            ],
        },
        delete_snapshots: {
            privileges: [
                'muwa_backup_repository:snapshot_delete',
            ],
            dependencies: [
                'muwa_backup_repository.viewer',
            ],
        },
    },
});
