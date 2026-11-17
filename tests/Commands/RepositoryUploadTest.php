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
namespace MuckiFacilityPlugin\Tests\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use MuckiFacilityPlugin\Commands\RepositoryUpload;
use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Services\RepositoryMirror;

class RepositoryUploadTest extends TestCase
{
    private const REPOSITORY_ID = '0123456789abcdef0123456789abcdef';

    private function mirrorResult(string $status, ?string $message = null): MirrorResult
    {
        $now = new \DateTimeImmutable();

        return new MirrorResult($status, 'cli', 'bucket', 'prefix', 'conn', $now, $now, 3, 2, 1, 1234, $message);
    }

    private function tester(RepositoryMirror $mirror, ?LockFactory $lockFactory = null): CommandTester
    {
        $command = new RepositoryUpload(
            new NullLogger(),
            $mirror,
            $lockFactory ?? new LockFactory(new InMemoryStore())
        );

        return new CommandTester($command);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function statusProvider(): array
    {
        return [
            'succeeded' => [MirrorResult::STATUS_SUCCEEDED, 0],
            'skipped' => [MirrorResult::STATUS_SKIPPED, 1],
            'failed' => [MirrorResult::STATUS_FAILED, 1],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testExitZeroOnlyOnSucceeded(string $status, int $expectedExit): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->method('mirror')->willReturn($this->mirrorResult($status, 'some message'));

        $tester = $this->tester($mirror);
        $exit = $tester->execute(['backupRepositoryId' => self::REPOSITORY_ID]);

        $this->assertSame($expectedExit, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString($status, $display);
        $this->assertStringContainsString('some message', $display);
        $this->assertStringContainsString('1234', $display);
    }

    public function testForcePassesIgnoreCheck(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects($this->once())
            ->method('mirror')
            ->with(self::REPOSITORY_ID, 'cli', null, true)
            ->willReturn($this->mirrorResult(MirrorResult::STATUS_SUCCEEDED));

        $this->assertSame(0, $this->tester($mirror)->execute([
            'backupRepositoryId' => self::REPOSITORY_ID,
            '--force' => true,
        ]));
    }

    public function testWithoutForceDoesNotIgnoreCheck(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects($this->once())
            ->method('mirror')
            ->with(self::REPOSITORY_ID, 'cli', null, false)
            ->willReturn($this->mirrorResult(MirrorResult::STATUS_SUCCEEDED));

        $this->tester($mirror)->execute(['backupRepositoryId' => self::REPOSITORY_ID]);
    }

    public function testNullResultExitsOne(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->method('mirror')->willReturn(null);

        $tester = $this->tester($mirror);
        $exit = $tester->execute(['backupRepositoryId' => self::REPOSITORY_ID]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no mirror configured, mirror already running, or unexpected error (see log)', $tester->getDisplay());
    }

    public function testBusyBackupLockExitsOne(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $held = $lockFactory->createLock('mucki-facility.backup', null);
        $this->assertTrue($held->acquire(false));

        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects($this->never())->method('mirror');

        $tester = $this->tester($mirror, $lockFactory);
        $exit = $tester->execute(['backupRepositoryId' => self::REPOSITORY_ID]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('backup is currently running', $tester->getDisplay());
    }

    public function testBackupLockIsReleasedAfterRun(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->method('mirror')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->tester($mirror, $lockFactory)->execute(['backupRepositoryId' => self::REPOSITORY_ID]);
            $this->fail('exception expected');
        } catch (\RuntimeException) {
        }

        $lock = $lockFactory->createLock('mucki-facility.backup', null);
        $this->assertTrue($lock->acquire(false));
    }

    public function testInvalidIdThrows(): void
    {
        $mirror = $this->createMock(RepositoryMirror::class);
        $mirror->expects($this->never())->method('mirror');

        $this->expectException(\InvalidArgumentException::class);
        $this->tester($mirror)->execute(['backupRepositoryId' => 'not-a-uuid']);
    }
}
