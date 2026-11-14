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
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Defaults;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Exception\RepositoryTakeoverException;
use MuckiFacilityPlugin\MessageQueue\Message\TakeoverRepositoryCheckMessage;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\DirectoryBrowser;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\RepositoryTakeover;
use MuckiFacilityPlugin\Services\SettingsInterface;

class RepositoryTakeoverTest extends TestCase
{
    private const PASSWORD = 'S3cr3t!';

    private string $root;
    private string $repoPath;
    /** @var BackupRepository&MockObject */
    private BackupRepository $backupRepository;
    /** @var BackupFileSnapshotsRepository&MockObject */
    private BackupFileSnapshotsRepository $snapshotsRepository;
    /** @var ManageRepository&MockObject */
    private ManageRepository $manageRepository;
    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $messageBus;
    private RepositoryTakeover $takeover;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/muwa-takeover-' . uniqid();
        $this->repoPath = $this->root . '/myrepo';
        $this->createRepositoryFolder($this->repoPath);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->root);

        $this->backupRepository = $this->createMock(BackupRepository::class);
        $this->snapshotsRepository = $this->createMock(BackupFileSnapshotsRepository::class);
        $this->manageRepository = $this->createMock(ManageRepository::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->takeover = new RepositoryTakeover(
            $this->createMock(LoggerInterface::class),
            $this->backupRepository,
            $this->snapshotsRepository,
            $this->manageRepository,
            new DirectoryBrowser($this->createMock(SettingsInterface::class), $kernel),
            new Helper(),
            $this->messageBus
        );
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->root);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectionProvider(): array
    {
        return [
            'invalid id' => [RepositoryTakeoverException::INVALID_INPUT . '|invalidId'],
            'empty path' => [RepositoryTakeoverException::INVALID_INPUT . '|emptyPath'],
            'blank password' => [RepositoryTakeoverException::INVALID_INPUT . '|blankPassword'],
            'path missing' => [RepositoryTakeoverException::PATH_NOT_FOUND . '|missingPath'],
            'no config' => [RepositoryTakeoverException::NOT_A_REPOSITORY . '|noConfig'],
            'locks not writable' => [RepositoryTakeoverException::NOT_WRITABLE . '|locksReadOnly'],
            'duplicate' => [RepositoryTakeoverException::DUPLICATE . '|duplicate'],
            'password not storable' => [RepositoryTakeoverException::PASSWORD_NOT_STORABLE . '|passwordNotStorable'],
            'restic fails' => [RepositoryTakeoverException::ACCESS_FAILED . '|resticFails'],
        ];
    }

    #[DataProvider('rejectionProvider')]
    public function testRejectionsWriteNothing(string $case): void
    {
        [$expectedCode, $scenario] = explode('|', $case);

        $exception = $this->runRejection($scenario, 'pw');

        static::assertSame($expectedCode, $exception->getErrorCode());
    }

    public function testNotWritableNamesUserAndPath(): void
    {
        $exception = $this->runRejection('locksReadOnly', 'pw');

        static::assertSame(RepositoryTakeoverException::NOT_WRITABLE, $exception->getErrorCode());
        static::assertStringEndsWith('/locks', $exception->getMeta()['path']);
        static::assertNotSame('', $exception->getMeta()['user']);
    }

    public function testExceptionMessagesNeverContainPassword(): void
    {
        $scenarios = array_map(
            static fn (array $case): string => explode('|', $case[0])[1],
            array_values(self::rejectionProvider())
        );
        foreach ($scenarios as $scenario) {
            if ($scenario === 'locksReadOnly' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
                continue;
            }
            $this->setUp();
            try {
                $exception = $this->runRejection($scenario, self::PASSWORD);
            } finally {
                $this->tearDown();
            }

            static::assertStringNotContainsString(self::PASSWORD, $exception->getMessage(), $scenario);
            static::assertStringNotContainsString(self::PASSWORD, (string) json_encode($exception->getMeta()), $scenario);
        }
    }

    public function testSuccessCreatesInactiveRecordWithDefaults(): void
    {
        $id = Uuid::randomHex();
        $this->manageRepository->expects(static::once())->method('readSnapshots')
            ->with($this->root . '/myrepo', ' pa ss ')->willReturn([['id' => 'a'], ['id' => 'b']]);
        $this->backupRepository->expects(static::once())->method('prepareStoredPassword')
            ->with(' pa ss ', PasswordSource::ENCRYPTED)->willReturn('stored');
        /** @var BackupRepositorySettings|null $captured */
        $captured = null;
        $this->backupRepository->expects(static::once())->method('createBackupRepository')
            ->willReturnCallback(static function (BackupRepositorySettings $settings, string $stored) use (&$captured): void {
                $captured = $settings;
                static::assertSame('stored', $stored);
            });
        $this->snapshotsRepository->expects(static::once())->method('createNewSnapshots')->willReturn(2);
        $this->expectDispatch($id);

        $count = $this->takeover->takeover($id, $this->root . '/myrepo/', ' pa ss ');

        static::assertSame(2, $count);
        static::assertInstanceOf(BackupRepositorySettings::class, $captured);
        static::assertFalse($captured->isActive());
        static::assertSame('myrepo', $captured->getName());
        static::assertSame($this->root . '/myrepo', $captured->getRepositoryPath());
        static::assertSame([], $captured->getBackupPaths());
        static::assertSame(' pa ss ', $captured->getRepositoryPassword());
        static::assertSame(0, $captured->getForgetDaily());
        static::assertSame(0, $captured->getForgetWeekly());
        static::assertSame(0, $captured->getForgetMonthly());
        static::assertSame(0, $captured->getForgetYearly());
        static::assertSame('', $captured->getRestorePath());
        static::assertNull($captured->getDbDumpPath());
        static::assertNull($captured->getDatabaseConnectionId());
    }

    public function testHostnameComesFromLatestSnapshotByTime(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([
            ['time' => '2024-03-01T23:30:00Z', 'hostname' => 'a'],
            ['time' => '2024-03-02T00:10:00+02:00', 'hostname' => 'b'],
            ['time' => 'garbage', 'hostname' => 'c'],
        ]);

        static::assertSame('a', $this->takeoverAndCapture()->getHostName());
    }

    public function testEmptyHostnameOfLatestSnapshotUsesDefaultHostname(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([
            ['time' => '2024-03-01T10:00:00Z', 'hostname' => 'a'],
            ['time' => '2024-03-02T10:00:00Z', 'hostname' => '  '],
        ]);

        static::assertSame(Defaults::DEFAULT_REPOSITORY_HOST_NAME, $this->takeoverAndCapture()->getHostName());
    }

    public function testSnapshotWithoutHostnameKeyIsStillTheLatest(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([
            ['time' => '2024-03-01T10:00:00Z', 'hostname' => 'a'],
            ['time' => '2024-03-02T10:00:00Z'],
        ]);

        static::assertSame(Defaults::DEFAULT_REPOSITORY_HOST_NAME, $this->takeoverAndCapture()->getHostName());
    }

    public function testOverlongHostnameUsesDefaultHostname(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([
            ['time' => '2024-03-02T10:00:00Z', 'hostname' => str_repeat('h', 129)],
        ]);

        static::assertSame(Defaults::DEFAULT_REPOSITORY_HOST_NAME, $this->takeoverAndCapture()->getHostName());
    }

    public function testPathLongerThanColumnIsRejectedAfterNormalization(): void
    {
        $longPath = $this->root . '/' . str_repeat('a', 250);
        $this->manageRepository->expects(static::never())->method('readSnapshots');
        $this->backupRepository->expects(static::never())->method('createBackupRepository');

        try {
            $this->takeover->takeover(Uuid::randomHex(), $longPath, 'pw');
            static::fail('Expected invalid input');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::INVALID_INPUT, $e->getErrorCode());
        }
    }

    public function testSaveFailureIsMappedToSaveFailed(): void
    {
        $dbError = new \RuntimeException('Data too long for column');
        $this->manageRepository->method('readSnapshots')->willReturn([]);
        $this->backupRepository->method('createBackupRepository')->willThrowException($dbError);
        $this->snapshotsRepository->expects(static::never())->method('createNewSnapshots');
        $this->messageBus->expects(static::never())->method('dispatch');

        try {
            $this->takeover->takeover(Uuid::randomHex(), $this->repoPath, self::PASSWORD);
            static::fail('Expected save failure');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::SAVE_FAILED, $e->getErrorCode());
            static::assertSame($dbError, $e->getPrevious());
            static::assertStringNotContainsString('Data too long', $e->getMessage());
        }
    }

    public function testDuplicateLookupFailureIsMappedToSaveFailed(): void
    {
        $this->backupRepository->method('getBackupRepositoryByPath')->willThrowException(new \RuntimeException('db'));
        $this->backupRepository->expects(static::never())->method('createBackupRepository');

        try {
            $this->takeover->takeover(Uuid::randomHex(), $this->repoPath, 'pw');
            static::fail('Expected save failure');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::SAVE_FAILED, $e->getErrorCode());
        }
    }

    public function testAccessFailedDetailHasNoCommandLine(): void
    {
        $process = new Process(['sh', '-c', 'echo "Fatal: wrong password or no key found" >&2; exit 1']);
        $process->run();
        $this->manageRepository->method('readSnapshots')->willThrowException(new ProcessFailedException($process));

        $exception = $this->runRejection('none', self::PASSWORD);

        static::assertSame(RepositoryTakeoverException::ACCESS_FAILED, $exception->getErrorCode());
        static::assertSame('Fatal: wrong password or no key found', $exception->getMeta()['detail']);
        static::assertStringNotContainsString('sh -c', $exception->getMessage() . json_encode($exception->getMeta()));
    }

    public function testAccessFailedWithoutOutputGetsGenericHint(): void
    {
        $this->manageRepository->method('readSnapshots')->willThrowException(new \RuntimeException(''));

        $exception = $this->runRejection('none', 'pw');

        static::assertSame('check password and file permissions', $exception->getMeta()['detail']);
    }

    public function testResticFailureDetailIsInMeta(): void
    {
        $exception = $this->runRejection('resticFails', 'pw');

        static::assertSame('Fatal: wrong password or no key found', $exception->getMeta()['detail']);
    }

    public function testNotWritableDataSubdirectoryIsReported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('root ignores directory permissions');
        }
        mkdir($this->repoPath . '/data/ab');
        chmod($this->repoPath . '/data/ab', 0555);

        $exception = $this->runRejection('none', 'pw');

        static::assertSame(RepositoryTakeoverException::NOT_WRITABLE, $exception->getErrorCode());
        static::assertStringEndsWith('/data/ab', $exception->getMeta()['path']);
    }

    public function testEmptyRepositoryUsesDefaultHostname(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([]);

        $captured = $this->takeoverAndCapture(0);

        static::assertSame(Defaults::DEFAULT_REPOSITORY_HOST_NAME, $captured->getHostName());
    }

    public function testDuplicateMatchesTrailingSlashVariant(): void
    {
        $this->createRepositoryFolder($this->root . '/repo');
        $this->backupRepository->expects(static::once())->method('getBackupRepositoryByPath')
            ->with($this->root . '/repo')->willReturn(new BackupRepositoryEntity());

        try {
            $this->takeover->takeover(Uuid::randomHex(), $this->root . '/repo//', 'pw');
            static::fail('Expected duplicate');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::DUPLICATE, $e->getErrorCode());
            static::assertSame('repo', $e->getMeta()['name']);
        }
    }

    public function testImportFailureRemovesRecord(): void
    {
        $id = Uuid::randomHex();
        $order = [];
        $this->manageRepository->method('readSnapshots')->willReturn([]);
        $importError = new \RuntimeException('db down');
        $this->snapshotsRepository->method('createNewSnapshots')->willThrowException($importError);
        $this->snapshotsRepository->expects(static::once())->method('removeOldSnapshots')->with($id)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'snapshots';
            });
        $this->backupRepository->expects(static::once())->method('createBackupRepository');
        $this->backupRepository->expects(static::once())->method('removeBackupRepository')->with($id)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'record';
            });
        $this->messageBus->expects(static::never())->method('dispatch');

        try {
            $this->takeover->takeover($id, $this->repoPath, 'pw');
            static::fail('Expected import failure');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::IMPORT_FAILED, $e->getErrorCode());
            static::assertSame($importError, $e->getPrevious());
        }
        static::assertSame(['snapshots', 'record'], $order);
    }

    public function testFailingRollbackStillReportsImportFailed(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([]);
        $importError = new \RuntimeException('db down');
        $this->snapshotsRepository->method('createNewSnapshots')->willThrowException($importError);
        $this->backupRepository->method('removeBackupRepository')
            ->willThrowException(new \RuntimeException('FK violation'));

        try {
            $this->takeover->takeover(Uuid::randomHex(), $this->repoPath, 'pw');
            static::fail('Expected import failure');
        } catch (RepositoryTakeoverException $e) {
            static::assertSame(RepositoryTakeoverException::IMPORT_FAILED, $e->getErrorCode());
            static::assertSame($importError, $e->getPrevious());
        }
    }

    public function testFailingDispatchDoesNotUndoTakeover(): void
    {
        $this->manageRepository->method('readSnapshots')->willReturn([]);
        $this->snapshotsRepository->method('createNewSnapshots')->willReturn(0);
        $this->messageBus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));
        $this->backupRepository->expects(static::never())->method('removeBackupRepository');

        static::assertSame(0, $this->takeover->takeover(Uuid::randomHex(), $this->repoPath, 'pw'));
    }

    private function takeoverAndCapture(int $imported = 1): BackupRepositorySettings
    {
        $captured = null;
        $this->backupRepository->method('createBackupRepository')
            ->willReturnCallback(static function (BackupRepositorySettings $settings) use (&$captured): void {
                $captured = $settings;
            });
        $this->snapshotsRepository->method('createNewSnapshots')->willReturn($imported);
        $this->messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message)
        );

        static::assertSame($imported, $this->takeover->takeover(Uuid::randomHex(), $this->repoPath, 'pw'));
        static::assertInstanceOf(BackupRepositorySettings::class, $captured);

        return $captured;
    }

    private function expectDispatch(string $id): void
    {
        $this->messageBus->expects(static::once())->method('dispatch')
            ->with(static::callback(
                static fn (mixed $message): bool => $message instanceof TakeoverRepositoryCheckMessage
                    && $message->getBackupRepositoryId() === $id
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
    }

    private function runRejection(string $scenario, string $password): RepositoryTakeoverException
    {
        $id = Uuid::randomHex();
        $path = $this->repoPath;

        switch ($scenario) {
            case 'invalidId':
                $id = 'nope';
                break;
            case 'emptyPath':
                $path = '   ';
                break;
            case 'blankPassword':
                $password = '   ';
                break;
            case 'missingPath':
                $path = $this->root . '/does-not-exist';
                break;
            case 'noConfig':
                unlink($this->repoPath . '/config');
                break;
            case 'locksReadOnly':
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    static::markTestSkipped('root ignores directory permissions');
                }
                chmod($this->repoPath . '/locks', 0555);
                break;
            case 'duplicate':
                $this->backupRepository->method('getBackupRepositoryByPath')->willReturn(new BackupRepositoryEntity());
                break;
            case 'passwordNotStorable':
                $this->backupRepository->method('prepareStoredPassword')
                    ->willThrowException(new \RuntimeException('secret missing ' . $password));
                break;
            case 'resticFails':
                $this->manageRepository->method('readSnapshots')
                    ->willThrowException(new \RuntimeException('Fatal: wrong password or no key found'));
                break;
        }

        $this->backupRepository->expects(static::never())->method('createBackupRepository');
        $this->snapshotsRepository->expects(static::never())->method('createNewSnapshots');
        $this->messageBus->expects(static::never())->method('dispatch');

        try {
            $this->takeover->takeover($id, $path, $password);
        } catch (RepositoryTakeoverException $e) {
            return $e;
        }
        static::fail('Expected RepositoryTakeoverException for ' . $scenario);
    }

    private function createRepositoryFolder(string $path): void
    {
        mkdir($path, 0777, true);
        file_put_contents($path . '/config', 'x');
        foreach (RepositoryTakeover::REQUIRED_WRITABLE_DIRECTORIES as $directory) {
            mkdir($path . '/' . $directory);
        }
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0777);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeRecursive($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
