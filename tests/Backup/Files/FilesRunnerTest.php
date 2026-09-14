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
    private function createFilesRunner(BackupRepositorySettings $createBackup): FilesRunner
    {
        return new FilesRunner(
            $this->createMock(LoggerInterface::class),
            $this->createMock(PluginSettings::class),
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
}
