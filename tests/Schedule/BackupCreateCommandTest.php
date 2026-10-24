<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Schedule;

use MuckiFacilityPlugin\Commands\BackupCreate;
use MuckiFacilityPlugin\Schedule\BackupExecution;
use MuckiFacilityPlugin\Services\Backup;
use MuckiFacilityPlugin\Services\CliOutput;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\SettingsInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCreateCommandTest extends TestCase
{
    public function testReturnsFailureWhenSharedExecutionFails(): void
    {
        $settings=$this->createMock(SettingsInterface::class); $settings->method('isEnabled')->willReturn(true);
        $execution=$this->createMock(BackupExecution::class); $execution->method('execute')->willThrowException(new \RuntimeException('backup failed'));
        $command=new BackupCreate($this->createMock(LoggerInterface::class),$settings,$this->createMock(Backup::class),$execution,$this->createMock(Helper::class),new CliOutput());
        $tester=new CommandTester($command);
        self::assertSame(Command::FAILURE,$tester->execute(['backupRepositoryId'=>Uuid::randomHex()]));
        self::assertStringContainsString('backup failed',$tester->getDisplay());
    }
}
