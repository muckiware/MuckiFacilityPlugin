<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Backup\BackupFactory;
use MuckiFacilityPlugin\Backup\BackupInterface;
use MuckiFacilityPlugin\Exception\InvalidBackupTypeException;

class BackupFactoryTest extends TestCase
{
    public function testCreateRunner(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');
        $backupFactory = new BackupFactory($logger);
        $runner = $backupFactory->createBackupClient(BackupTypes::DATABASE_ALL->value);
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
        $backupFactory = new BackupFactory($logger);
        $runner = $backupFactory->createBackupClient('test');
    }
}
