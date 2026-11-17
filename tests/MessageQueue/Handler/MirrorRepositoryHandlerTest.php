<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\MessageQueue\Handler;

use MuckiFacilityPlugin\MessageQueue\Handler\MirrorRepositoryHandler;
use MuckiFacilityPlugin\MessageQueue\Message\MirrorRepositoryMessage;
use MuckiFacilityPlugin\Services\RepositoryMirror;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MirrorRepositoryHandlerTest extends TestCase
{
    public function testHandlerPassesBackupTriggerAndCheckId(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects(self::once())->method('mirror')->with('repo-id', 'backup', 'check-id');

        (new MirrorRepositoryHandler($this->createMock(LoggerInterface::class), $mirror))(
            new MirrorRepositoryMessage('repo-id', 'check-id')
        );
    }

    public function testHandlerPassesNullCheckId(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects(self::once())->method('mirror')->with('repo-id', 'backup', null);

        (new MirrorRepositoryHandler($this->createMock(LoggerInterface::class), $mirror))(
            new MirrorRepositoryMessage('repo-id', null)
        );
    }

    public function testHandlerNeverThrows(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->method('mirror')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new MirrorRepositoryHandler($logger, $mirror))(new MirrorRepositoryMessage('repo-id', null));
        self::assertTrue(true);
    }

    public function testMessageCarriesNoSecrets(): void
    {
        self::assertSame(
            ['backupRepositoryId', 'checkId'],
            array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionObject(new MirrorRepositoryMessage('repo-id', 'check-id')))->getProperties()
            )
        );
    }
}
