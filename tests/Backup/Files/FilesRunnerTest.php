<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Backup\Files;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Backup\Files\FilesRunner;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class FilesRunnerTest extends TestCase
{
    private function createFilesRunner(BackupRepositorySettings $createBackup, ?PluginSettings $settings = null): FilesRunner
    {
        return new FilesRunner(
            $this->createMock(LoggerInterface::class),
            $settings ?? $this->createMock(PluginSettings::class),
            $createBackup,
            $this->createMock(ServicesCliOutput::class)
        );
    }

    public function testGetBackupResultsIsEmptyArrayBeforeAnyRun(): void
    {
        $filesRunner = $this->createFilesRunner(new BackupRepositorySettings());

        static::assertSame(
            [],
            $filesRunner->getBackupResults(),
            'getBackupResults should return an empty array before any backup result was added'
        );
    }

    public function testGetBackupResultsIsEmptyArrayWithoutBackupPaths(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupPaths([]);
        $createBackup->setRepositoryPath('/tmp/muwa-repository');
        $createBackup->setRepositoryPassword('test');

        $filesRunner = $this->createFilesRunner($createBackup);
        $filesRunner->createBackupData();

        static::assertSame(
            [],
            $filesRunner->getBackupResults(),
            'getBackupResults should return an empty array when there is nothing to back up'
        );
    }

    /**
     * Without this, MuckiRestic\Library\Backup\Local::createBackup() runs `restic unlock` and
     * `restic prune` before every single restic backup call. createBackupData() reuses one
     * client across every configured backup path, so prune - the most expensive restic
     * operation - would run once per path instead of once per backup job, and unlock removes
     * ALL locks unconditionally, including one held by a genuinely running concurrent
     * operation. Disk space is already reclaimed on its own schedule elsewhere - see the
     * comment on FilesRunner::prepareBackupClient() for where.
     */
    public function testPrepareBackupClientSkipsImplicitUnlockAndPrune(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setRepositoryPath('/tmp/muwa-repository');
        $createBackup->setRepositoryPassword('test');

        $filesRunner = $this->createFilesRunner($createBackup);
        $backupClient = $filesRunner->prepareBackupClient();

        static::assertTrue(
            $backupClient->isSkipPrepareBackup(),
            'FilesRunner must disable the implicit unlock+prune before every restic backup call'
        );
    }

    /**
     * muckiware/restic stops every restic process after 3600 s by default, which aborts
     * long-running backups of large paths.
     */
    public function testPrepareBackupClientUsesConfiguredResticTimeout(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setRepositoryPath('/tmp/muwa-repository');
        $createBackup->setRepositoryPassword('test');

        $settings = $this->createMock(PluginSettings::class);
        $settings->method('getResticTimeout')->willReturn(14400.0);

        $backupClient = $this->createFilesRunner($createBackup, $settings)->prepareBackupClient();

        static::assertSame(14400.0, $backupClient->getProcessTimeout());
    }

    public function testPrepareBackupClientRemovesResticTimeoutWhenUnlimited(): void
    {
        $createBackup = new BackupRepositorySettings();
        $createBackup->setRepositoryPath('/tmp/muwa-repository');
        $createBackup->setRepositoryPassword('test');

        $settings = $this->createMock(PluginSettings::class);
        $settings->method('getResticTimeout')->willReturn(null);

        $backupClient = $this->createFilesRunner($createBackup, $settings)->prepareBackupClient();

        static::assertNull($backupClient->getProcessTimeout());
    }
}
