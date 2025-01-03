<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Core\Database\Database as CoreDatabase;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class BackupRunnerFactoryTest extends TestCase
{
    public function testCreateRunnerFile(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $settings = $this->createMock(SettingsInterface::class);
        $coreDatabase = $this->createMock(CoreDatabase::class);
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $coreDatabase, $servicesCliOutput);
        $createBackup = new CreateBackupEntity();
        $createBackup->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $runner = $backupFactory->createBackupRunner($createBackup);
        $this->assertInstanceOf(
            BackupInterface::class,
            $runner,
            'BackupInterface should be implemented'
        );
    }

    public function testCreateRunnerFiles(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $settings = $this->createMock(SettingsInterface::class);
        $coreDatabase = $this->createMock(CoreDatabase::class);
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $coreDatabase, $servicesCliOutput);
        $createBackup = new CreateBackupEntity();
        $createBackup->setBackupType(BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value);
        $runner = $backupFactory->createBackupRunner($createBackup);
        $this->assertInstanceOf(
            BackupInterface::class,
            $runner,
            'BackupInterface should be implemented'
        );
    }

    public function testCreateWrongRunner(): void
    {
        $this->expectException(InvalidBackupTypeException::class);
        $this->expectExceptionMessage('Invalid backup type provided');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $settings = $this->createMock(SettingsInterface::class);
        $coreDatabase = $this->createMock(CoreDatabase::class);
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $coreDatabase, $servicesCliOutput);
        $createBackup = new CreateBackupEntity();
        $createBackup->setBackupType('test');
        $runner = $backupFactory->createBackupRunner($createBackup);
    }
}
