const {Module} = Shopware;

import './page/muwa-backup-repository-list';
import './page/muwa-backup-repository-create';
import './page/muwa-backup-repository-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Component.register('muwa-backup-repository-entity-path-select', () => import('./component/muwa-backup-repository-entity-path-select'));

Module.register('muwa-backup-repository', {

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
                parentPath: 'sw.settings.index.plugins'
            }
        },
        create: {
            component: 'muwa-backup-repository-create',
            path: 'create',
            meta: {
                parentPath: 'muwa.backup.repository.index'
            }
        },
        detail: {
            component: 'muwa-backup-repository-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'muwa.backup.repository.index'
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
            label: 'muwa-backup-repository.general.mainMenuLabel',
        }
    ]
});
