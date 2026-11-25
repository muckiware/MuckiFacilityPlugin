<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class ByteSizeAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';

    public function testHelperFormatsUpToTerabytes(): void
    {
        $helper = (string) file_get_contents(self::MODULE . 'helper/format-bytes.js');

        self::assertStringContainsString("const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];", $helper);
        self::assertStringContainsString('unit < UNITS.length - 1', $helper);
    }

    public function testAllSizeColumnsUseTheSharedHelper(): void
    {
        $import = "import formatBytes from '../../helper/format-bytes';";

        foreach ([
            'page/muwa-backup-repository-detail/index.js',
            'component/muwa-backup-status-dashboard-widget/index.js',
            'component/muwa-s3-mirror-status-dashboard-widget/index.js',
        ] as $file) {
            $component = (string) file_get_contents(self::MODULE . $file);

            self::assertStringContainsString($import, $component, $file);
            self::assertStringContainsString('return formatBytes(value, this.$t.bind(this));', $component, $file);
            self::assertStringNotContainsString('Shopware.Utils.format.fileSize', $component, $file);
        }
    }
}
