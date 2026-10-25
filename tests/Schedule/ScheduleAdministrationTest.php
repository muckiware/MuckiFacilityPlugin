<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use PHPUnit\Framework\TestCase;

final class ScheduleAdministrationTest extends TestCase
{
    public function testTimezoneUsesShopwareTimezoneOptionsInSingleSelect(): void
    {
        $administrationPath = __DIR__ . '/../../src/Resources/app/administration/src/module/'
            . 'muwa-backup-repository/page/muwa-backup-repository-detail/';
        $component = file_get_contents($administrationPath . 'index.js');
        $template = file_get_contents($administrationPath . 'muwa-backup-repository-detail.html.twig');

        self::assertIsString($component);
        self::assertIsString($template);
        self::assertStringContainsString('timezoneOptions: []', $component);
        self::assertStringContainsString(
            "Shopware.Service('timezoneService').getTimezoneOptions()",
            $component
        );
        self::assertMatchesRegularExpression(
            '/<sw-single-select[^>]*v-model:value="schedule\.timezone"[^>]*:options="timezoneOptions"/s',
            $template
        );
        self::assertStringNotContainsString(
            '<sw-text-field v-if="isV6600" v-model:value="schedule.timezone"',
            $template
        );
    }

    public function testCronExpressionDiagramIsShownAboveField(): void
    {
        $modulePath = __DIR__ . '/../../src/Resources/app/administration/src/module/';
        $detailPath = $modulePath . 'muwa-backup-repository/page/muwa-backup-repository-detail/';
        $template = file_get_contents($detailPath . 'muwa-backup-repository-detail.html.twig');
        $germanSnippets = json_decode(
            (string) file_get_contents($modulePath . 'muwa-backup-repository/snippet/de-DE.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsString($template);
        self::assertMatchesRegularExpression(
            '/<pre class="muwa-backup-schedule__cron-description">.*cronExpressionDescription.*<\/pre>.*v-model:value="schedule\.cronExpression"/s',
            $template
        );
        self::assertSame(
            "┌───────────── Minute (0 - 59)\n"
            . "│ ┌───────────── Stunde (0 - 23)\n"
            . "│ │ ┌───────────── Tag des Monats (1 - 31)\n"
            . "│ │ │ ┌───────────── Monat (1 - 12)\n"
            . "│ │ │ │ ┌───────────── Wochentag (0 - 6)\n"
            . "│ │ │ │ │\n"
            . "* * * * *\n\n"
            . "Beispiele:\n"
            . "30 3 * * * → täglich um 03:30 Uhr\n"
            . "30 */3 * * * → alle drei Stunden zur Minute 30",
            $germanSnippets['muwa-backup-repository']['schedule']['cronExpressionDescription']
        );
    }
}
