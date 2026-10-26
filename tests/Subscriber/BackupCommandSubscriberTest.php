<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Subscriber;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Services\BackupCommandExecutor;
use MuckiFacilityPlugin\Subscriber\BackupCommandSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class BackupCommandSubscriberTest extends TestCase
{
    public function testConfiguredCommandsAreExecutedForBackupLifecycleEvents(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setPreBackupCommandType('batch');
        $settings->setPreBackupCommand('/hooks/pre.sh');
        $settings->setPreBackupCommandArguments('/var/www/html --mode fast');
        $settings->setPreBackupFailOnError(true);
        $settings->setPostBackupCommandType('php');
        $settings->setPostBackupCommand('cache:clear');
        $settings->setPostBackupCommandArguments('ignored for php');
        $settings->setPostBackupFailOnError(false);

        $calls = [];
        $executor = $this->createMock(BackupCommandExecutor::class);
        $executor->expects(static::exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $type, string $command, bool $failOnError, string $arguments) use (&$calls): void {
                $calls[] = [$type, $command, $failOnError, $arguments];
            });

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BackupCommandSubscriber($executor));

        $dispatcher->dispatch(new BeforeBackupCreatedEvent($settings), BeforeBackupCreatedEvent::NAME);
        $dispatcher->dispatch(new AfterBackupCreatedEvent($settings, []), AfterBackupCreatedEvent::NAME);

        static::assertSame([
            ['batch', '/hooks/pre.sh', true, '/var/www/html --mode fast'],
            ['php', 'cache:clear', false, 'ignored for php'],
        ], $calls);
    }
}
