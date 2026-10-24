<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class LastBackupAtAdministrationTest extends TestCase
{
    public function testRepositoryListShowsLatestStatusDateAsLastBackupRun(): void
    {
        $modulePath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
        $component = file_get_contents($modulePath . 'page/muwa-backup-repository-list/index.js');
        $template = file_get_contents($modulePath . 'page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig');

        self::assertIsString($component);
        self::assertIsString($template);
        self::assertStringContainsString("property: 'lastBackupAt'", $component);
        self::assertStringContainsString("'muwa-backup-repository.list.column-last-backup-at'", $component);
        self::assertStringContainsString("criteria.getAssociation('backupRepositoryStats')", $component);
        self::assertStringContainsString("Criteria.sort('createdAt', 'DESC')", $component);
        self::assertStringContainsString('.setLimit(1)', $component);
        self::assertStringContainsString('getLatestStats(item)', $component);
        self::assertStringContainsString('#column-lastBackupAt="{ item }"', $template);
        self::assertStringContainsString('getLatestStats(item)?.createdAt', $template);
    }

    public function testDashboardShowsLatestStatusDateAsLastBackupRun(): void
    {
        $modulePath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
        $component = file_get_contents($modulePath . 'component/muwa-backup-status-dashboard-widget/index.js');
        $template = file_get_contents($modulePath . 'component/muwa-backup-status-dashboard-widget/muwa-backup-status-dashboard-widget.html.twig');

        self::assertIsString($component);
        self::assertIsString($template);
        self::assertStringContainsString('lastBackupAt: latestStats?.createdAt ?? null', $component);
        self::assertStringContainsString("property: 'lastBackupAt'", $component);
        self::assertStringContainsString("label: 'muwa-backup-repository.dashboard.lastBackupAtLabel'", $component);
        self::assertStringContainsString('#column-lastBackupAt="{ item }"', $template);
        self::assertStringContainsString('item.lastBackupAt ? dateFilter(item.lastBackupAt', $template);
    }

    public function testLastBackupRunLabelsAreTranslated(): void
    {
        $snippetPath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/snippet/';
        $german = json_decode((string) file_get_contents($snippetPath . 'de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $english = json_decode((string) file_get_contents($snippetPath . 'en-GB.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'Letzter Backup-Lauf',
            $german['muwa-backup-repository']['list']['column-last-backup-at']
        );
        self::assertSame(
            'Letzter Backup-Lauf',
            $german['muwa-backup-repository']['dashboard']['lastBackupAtLabel']
        );
        self::assertSame(
            'Last backup run',
            $english['muwa-backup-repository']['list']['column-last-backup-at']
        );
        self::assertSame(
            'Last backup run',
            $english['muwa-backup-repository']['dashboard']['lastBackupAtLabel']
        );
    }
}
