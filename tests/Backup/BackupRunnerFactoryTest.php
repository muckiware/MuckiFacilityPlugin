<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupRunnerFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Services\DatabaseConnectionResolver;
use MuckiFacilityPlugin\Backup\Files\FilesRunner;
use MuckiFacilityPlugin\Backup\Database\CompleteFileRunner;
use MuckiFacilityPlugin\Backup\Database\CompleteFilesRunner;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

class BackupRunnerFactoryTest extends TestCase
{
    public function testCreateRunnerFile(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $settings = $this->createMock(SettingsInterface::class);
        $resolver = $this->createMock(DatabaseConnectionResolver::class);
        $resolver->method('resolveForBackup')->willReturn($this->createTarget());
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $servicesCliOutput, $resolver);
        $createBackup = new BackupRepositorySettings();
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
        $resolver = $this->createMock(DatabaseConnectionResolver::class);
        $resolver->method('resolveForBackup')->willReturn($this->createTarget());
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $servicesCliOutput, $resolver);
        $createBackup = new BackupRepositorySettings();
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
        $resolver = $this->createMock(DatabaseConnectionResolver::class);
        $resolver->method('resolveForBackup')->willReturn($this->createTarget());
        $servicesCliOutput = $this->createMock(ServicesCliOutput::class);

        $backupFactory = new BackupRunnerFactory($logger, $settings, $servicesCliOutput, $resolver);
        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupType('test');
        $runner = $backupFactory->createBackupRunner($createBackup);
    }

    public function testDatabaseRunnersReceiveResolvedTarget(): void
    {
        $createBackup = new BackupRepositorySettings();
        $target = $this->createTarget();

        $resolver = $this->createMock(DatabaseConnectionResolver::class);
        $resolver->expects(static::exactly(2))
            ->method('resolveForBackup')
            ->with($createBackup)
            ->willReturn($target);

        $factory = new BackupRunnerFactory(
            $this->createMock(LoggerInterface::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(ServicesCliOutput::class),
            $resolver
        );

        $createBackup->setBackupType(BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value);
        $single = $factory->createBackupRunner($createBackup);
        $createBackup->setBackupType(BackupTypes::COMPLETE_DATABASE_SEPARATE_FILES->value);
        $separate = $factory->createBackupRunner($createBackup);

        static::assertInstanceOf(CompleteFileRunner::class, $single);
        static::assertInstanceOf(CompleteFilesRunner::class, $separate);
        foreach ([$single, $separate] as $runner) {
            $property = new \ReflectionProperty($runner, 'target');
            static::assertSame($target, $property->getValue($runner));
        }
    }

    public function testFilesRunnerDoesNotResolveConnection(): void
    {
        $resolver = $this->createMock(DatabaseConnectionResolver::class);
        $resolver->expects(static::never())->method('resolveForBackup');

        $factory = new BackupRunnerFactory(
            $this->createMock(LoggerInterface::class),
            $this->createMock(SettingsInterface::class),
            $this->createMock(ServicesCliOutput::class),
            $resolver
        );
        $createBackup = new BackupRepositorySettings();
        $createBackup->setBackupType(BackupTypes::FILES->value);

        static::assertInstanceOf(FilesRunner::class, $factory->createBackupRunner($createBackup));
    }

    private function createTarget(): DatabaseBackupTarget
    {
        return new DatabaseBackupTarget(
            new ResolvedDatabaseConnection('mysql', 'localhost', 3306, 'shopware', 'root', 'secret'),
            $this->createMock(DatabaseDumperInterface::class),
            true,
            'default'
        );
    }
}
