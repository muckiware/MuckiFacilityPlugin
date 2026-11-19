<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class BackupDurationAdministrationTest extends TestCase
{
    public function testDetailPageShowsBackupDurationInCurrentStatusAndHistory(): void
    {
        $pagePath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/page/muwa-backup-repository-detail/';
        $component = file_get_contents($pagePath . 'index.js');
        $template = file_get_contents($pagePath . 'muwa-backup-repository-detail.html.twig');

        self::assertIsString($component);
        self::assertIsString($template);
        self::assertStringContainsString('value: this.formatDuration(this.latestStats.backupDuration)', $component);
        self::assertStringContainsString("property: 'backupDuration'", $component);
        self::assertStringContainsString('#column-backupDuration="{ item }"', $template);
        self::assertStringContainsString('formatDuration(item.backupDuration)', $template);
    }

    public function testDashboardWidgetShowsBackupDurationOfLatestStats(): void
    {
        $componentPath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/component/muwa-backup-status-dashboard-widget/';
        $component = file_get_contents($componentPath . 'index.js');
        $template = file_get_contents($componentPath . 'muwa-backup-status-dashboard-widget.html.twig');

        self::assertIsString($component);
        self::assertIsString($template);
        self::assertStringContainsString('backupDuration: latestStats?.backupDuration ?? null', $component);
        self::assertStringContainsString("property: 'backupDuration'", $component);
        self::assertStringContainsString('#column-backupDuration="{ item }"', $template);
        self::assertStringContainsString('formatDuration(item.backupDuration)', $template);
    }

    public function testDetailPageAndDashboardWidgetShareTheDurationFormatter(): void
    {
        $modulePath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
        $import = "import formatDuration from '../../helper/format-duration';";

        self::assertFileExists($modulePath . 'helper/format-duration.js');
        self::assertStringContainsString($import, (string) file_get_contents($modulePath . 'page/muwa-backup-repository-detail/index.js'));
        self::assertStringContainsString($import, (string) file_get_contents($modulePath . 'component/muwa-backup-status-dashboard-widget/index.js'));
    }

    public function testDurationUnitsAreTranslatedWithValuePlaceholder(): void
    {
        $snippetPath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/snippet/';

        foreach (['de-DE.json', 'en-GB.json'] as $file) {
            $snippets = json_decode((string) file_get_contents($snippetPath . $file), true, 512, JSON_THROW_ON_ERROR);
            $detail = $snippets['muwa-backup-repository']['detail'];

            self::assertNotEmpty($detail['statsBackupDurationLabel'], $file);
            foreach (['durationSeconds', 'durationMinutes', 'durationHours'] as $key) {
                self::assertStringContainsString('{value}', $detail[$key], $file . ' ' . $key);
            }
        }
    }
}
