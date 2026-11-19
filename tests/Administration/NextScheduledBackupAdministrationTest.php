<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class NextScheduledBackupAdministrationTest extends TestCase
{
    private const MODULE_PATH = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';

    public function testHelperOnlyReturnsNextRunOfEnabledSchedule(): void
    {
        $helper = $this->read('helper/format-schedule-date.js');

        self::assertStringContainsString('export function getNextScheduledRun(schedule)', $helper);
        self::assertStringContainsString('if (!schedule?.enabled)', $helper);
        self::assertStringContainsString('return schedule.nextExecutionTime ?? null;', $helper);
        self::assertStringContainsString('export default function formatScheduleDate(value, timezone)', $helper);
        self::assertStringContainsString('Shopware.Utils.format.date(value', $helper);
    }

    public function testRepositoryListShowsNextScheduledBackup(): void
    {
        $component = $this->read('page/muwa-backup-repository-list/index.js');
        $template = $this->read('page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig');

        self::assertStringContainsString("import { getNextScheduledRun } from '../../helper/format-schedule-date'", $component);
        self::assertStringContainsString("criteria.addAssociation('schedule')", $component);
        self::assertStringContainsString("property: 'nextScheduledBackup'", $component);
        self::assertStringContainsString("'muwa-backup-repository.list.column-next-scheduled-backup'", $component);
        // same format as the column "Last backup run"
        self::assertStringContainsString("this.dateFilter(nextRun, { hour: '2-digit', minute: '2-digit' })", $component);
        self::assertStringContainsString('#column-nextScheduledBackup="{ item }"', $template);
        self::assertStringContainsString('formatNextScheduledBackup(item)', $template);
    }

    public function testDashboardShowsNextScheduledBackup(): void
    {
        $component = $this->read('component/muwa-backup-status-dashboard-widget/index.js');
        $template = $this->read('component/muwa-backup-status-dashboard-widget/muwa-backup-status-dashboard-widget.html.twig');

        self::assertStringContainsString("import { getNextScheduledRun } from '../../helper/format-schedule-date'", $component);
        self::assertStringContainsString("criteria.addAssociation('schedule')", $component);
        self::assertStringContainsString('nextScheduledBackup: getNextScheduledRun(backupRepository.schedule)', $component);
        // same format as the column "Last backup run"
        self::assertStringContainsString("this.dateFilter(item.nextScheduledBackup, { hour: '2-digit', minute: '2-digit' })", $component);
        self::assertStringContainsString("property: 'nextScheduledBackup'", $component);
        self::assertStringContainsString("label: 'muwa-backup-repository.dashboard.nextScheduledBackupLabel'", $component);
        self::assertStringContainsString('#column-nextScheduledBackup="{ item }"', $template);
        self::assertStringContainsString('formatNextScheduledBackup(item)', $template);
    }

    public function testDetailPageUsesSharedHelper(): void
    {
        $component = $this->read('page/muwa-backup-repository-detail/index.js');

        self::assertStringContainsString("from '../../helper/format-schedule-date'", $component);
    }

    public function testLabelsAreTranslated(): void
    {
        $german = $this->snippets('de-DE');
        $english = $this->snippets('en-GB');

        self::assertSame('Nächste Sicherung', $german['list']['column-next-scheduled-backup']);
        self::assertSame('Nächste Sicherung', $german['dashboard']['nextScheduledBackupLabel']);
        self::assertSame('Kein aktiver Zeitplan', $german['schedule']['noActiveSchedule']);
        self::assertSame('Next backup', $english['list']['column-next-scheduled-backup']);
        self::assertSame('Next backup', $english['dashboard']['nextScheduledBackupLabel']);
        self::assertSame('No active schedule', $english['schedule']['noActiveSchedule']);
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::MODULE_PATH . $relativePath);
        self::assertIsString($content, $relativePath);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function snippets(string $locale): array
    {
        $snippets = json_decode(
            (string) file_get_contents(self::MODULE_PATH . 'snippet/' . $locale . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $snippets['muwa-backup-repository'];
    }
}
