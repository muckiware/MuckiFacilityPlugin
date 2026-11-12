const { Component, Module, Feature } = Shopware;

import './page/muwa-backup-repository-list';
import './page/muwa-backup-repository-create';
import './page/muwa-backup-repository-detail';
import './acl';
import './component/muwa-backup-status-dashboard-widget';
import './component/muwa-restic-binary-info';
import './extension/sw-dashboard-index';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Component.register('muwa-backup-repository-entity-path-select', () => import('./component/muwa-backup-repository-entity-path-select'));
Shopware.Component.register('muwa-restore-directory-tree', () => import('./component/muwa-restore-directory-tree'));
Shopware.Component.register('muwa-backup-repository-path-field', () => import('./component/muwa-backup-repository-path-field'));
Shopware.Component.register('muwa-database-connection-select', () => import('./component/muwa-database-connection-select'));
Shopware.Component.register('muwa-database-connection-list', () => import('./page/muwa-database-connection-list'));
Shopware.Component.register('muwa-database-connection-detail', () => import('./page/muwa-database-connection-detail'));

Shopware.Module.register('muwa-backup-repository', {

    type: 'plugin',
    name: 'muwaBackupRepository',
    title: 'muwa-backup-repository.general.mainMenuLabel',
    description: 'muwa-backup-repository.general.description',
    color: '#c04d01',
    icon: 'regular-save',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },
    routes: {
        index: {
            component: 'muwa-backup-repository-list',
            path: ':tab?',
            meta: {
                parentPath: 'sw.settings.index.plugins',
                privilege: 'muwa_backup_repository.viewer'
            }
        },
        create: {
            component: 'muwa-backup-repository-create',
            path: 'create',
            meta: {
                parentPath: 'muwa.backup.repository.index',
                privilege: 'muwa_backup_repository.creator'
            }
        },
        detail: {
            component: 'muwa-backup-repository-detail',
            path: 'detail/:id/:tab?',
            props: {
                default(route) {
                    return {
                        id: route.params.id,
                        tab: 'backupRepositoryConfig',
                    };
                },
            },
            meta: {
                parentPath: 'muwa.backup.repository.index',
                privilege: 'muwa_backup_repository.viewer'
            }
        },
        databaseConnectionCreate: {
            component: 'muwa-database-connection-detail',
            path: 'database-connection/create',
            meta: {
                parentPath: 'muwa.backup.repository.index',
                privilege: 'muwa_backup_repository.creator'
            }
        },
        databaseConnectionDetail: {
            component: 'muwa-database-connection-detail',
            path: 'database-connection/detail/:id',
            meta: {
                parentPath: 'muwa.backup.repository.index',
                privilege: 'muwa_backup_repository.viewer'
            }
        }
    },
    settingsItem: [
        {
            name: 'muwa-backup-repository-list',
            to: 'muwa.backup.repository.index',
            group: 'plugins',
            icon: 'regular-save',
            backgroundEnabled: true,
            privilege: 'muwa_backup_repository.viewer',
            label: 'muwa-backup-repository.general.mainMenuLabel',
        }
    ]
});
