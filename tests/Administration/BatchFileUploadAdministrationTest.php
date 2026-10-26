<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class BatchFileUploadAdministrationTest extends TestCase
{
    public function testCreateAndDetailPagesOfferUploadsForPreAndPostBatchCommands(): void
    {
        $base = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/page/';

        foreach (['muwa-backup-repository-create', 'muwa-backup-repository-detail'] as $page) {
            $component = file_get_contents($base . $page . '/index.js');
            $template = file_get_contents($base . $page . '/' . $page . '.html.twig');

            self::assertIsString($component);
            self::assertIsString($template);
            self::assertStringContainsString("requestBatchUpload: '/_action/muwa/filesystem/batch-file'", $component);
            self::assertStringContainsString('uploadBatchFile(file, commandField)', $component);
            self::assertStringContainsString("this.backupRepository[commandField] = response.data.path", $component);
            self::assertStringContainsString('@change="onBatchFileSelected($event, \'preBackupCommand\')"', $template);
            self::assertStringContainsString('@change="onBatchFileSelected($event, \'postBackupCommand\')"', $template);
            self::assertSame(2, substr_count($template, 'accept=".sh,text/x-shellscript,application/x-sh"'));
            self::assertStringContainsString('v-model:value="backupRepository.preBackupCommandArguments"', $template);
            self::assertStringContainsString('v-model:value="backupRepository.postBackupCommandArguments"', $template);
            self::assertLessThan(
                strpos($template, 'ref="preBatchFileInput"'),
                strpos($template, 'v-model:value="backupRepository.preBackupCommandArguments"')
            );
            self::assertLessThan(
                strpos($template, 'ref="postBatchFileInput"'),
                strpos($template, 'v-model:value="backupRepository.postBackupCommandArguments"')
            );
        }
    }

    public function testUploadLabelsAreTranslated(): void
    {
        $snippetPath = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/snippet/';
        $german = json_decode((string) file_get_contents($snippetPath . 'de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $english = json_decode((string) file_get_contents($snippetPath . 'en-GB.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Shell-Skript hochladen', $german['muwa-backup-repository']['commands']['batchUploadButton']);
        self::assertSame('Parameter', $german['muwa-backup-repository']['commands']['batchArgumentsLabel']);
        self::assertSame('Shell script uploaded', $english['muwa-backup-repository']['commands']['batchUploadSuccess']);
        self::assertSame('Arguments', $english['muwa-backup-repository']['commands']['batchArgumentsLabel']);
    }
}
