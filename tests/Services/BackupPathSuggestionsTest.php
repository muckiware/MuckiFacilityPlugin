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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\BackupPathSuggestions;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\SettingsInterface;

class BackupPathSuggestionsTest extends TestCase
{
    private const PASSWORD = 'S3cr3t!';
    private const DUMP_FOLDER = '/var/www/project/var/db/backup';

    private string $root;
    private string $repositoryId;
    /** @var BackupRepository&MockObject */
    private BackupRepository $backupRepository;
    /** @var ManageRepository&MockObject */
    private ManageRepository $manageRepository;
    /** @var SettingsInterface&MockObject */
    private SettingsInterface $settings;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    private BackupPathSuggestions $suggestions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/muwa-suggest-' . uniqid();
        mkdir($this->root, 0777, true);
        $this->repositoryId = Uuid::randomHex();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->backupRepository = $this->createMock(BackupRepository::class);
        $this->manageRepository = $this->createMock(ManageRepository::class);
        $this->settings = $this->createMock(SettingsInterface::class);
        $this->settings->method('getBackupPath')->willReturn(self::DUMP_FOLDER . '/');

        $this->suggestions = new BackupPathSuggestions(
            $this->logger,
            $this->backupRepository,
            $this->manageRepository,
            $this->settings,
            new Helper()
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root . '/*') ?: [] as $entry) {
                is_dir($entry) ? rmdir($entry) : unlink($entry);
            }
            rmdir($this->root);
        }
    }

    /**
     * @param array<int, mixed> $backupPaths
     */
    private function givenRepository(array $backupPaths = [], bool $withRestorePath = true): void
    {
        $entity = new BackupRepositoryEntity();
        $entity->setUniqueIdentifier($this->repositoryId);
        $entity->setRepositoryPath('/srv/repo');
        if ($withRestorePath) {
            $entity->setRestorePath('/srv/restore');
        }
        $entity->setRepositoryPassword(self::PASSWORD);
        $entity->setDbDumpPath(null);
        $entity->setBackupPaths($backupPaths);
        $this->backupRepository->method('getBackupRepositoryById')->willReturn($entity);
    }

    /**
     * @param array<int, string> $paths
     * @return array<string, mixed>
     */
    private function snapshot(string $time, array $paths, string $hostname = 'host-a'): array
    {
        return ['id' => Uuid::randomHex(), 'time' => $time, 'hostname' => $hostname, 'paths' => $paths];
    }

    public function testPathsAreDeduplicatedAndSortedByLastSeen(): void
    {
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00.123456789Z', ['/srv/old'], 'host-old'),
            $this->snapshot('2026-03-01T12:00:00+02:00', ['/srv/shared', '/srv/new'], 'host-new'),
            $this->snapshot('2026-02-01T10:00:00Z', ['/srv/shared'], 'host-mid'),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertNull($result['error']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(['/srv/new', '/srv/shared', '/srv/old'], array_column($result['suggestions'], 'path'));
        // /srv/new and /srv/shared share the newest instant, ties are ordered by path
        $shared = $result['suggestions'][1];
        $this->assertSame(2, $shared['snapshotCount']);
        $this->assertSame('host-new', $shared['hostname']);
        $this->assertSame('2026-03-01T10:00:00+00:00', (new \DateTime($shared['lastSeenAt']))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testPathWithCommaStaysOnePath(): void
    {
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', ['/srv/a,b']),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('/srv/a,b', $result['suggestions'][0]['path']);
    }

    public function testStateDirectoryFileMissing(): void
    {
        $dir = $this->root . '/dir';
        $file = $this->root . '/file.txt';
        mkdir($dir);
        file_put_contents($file, 'x');
        $missing = $this->root . '/missing';

        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', [$dir, $file, $missing]),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);
        $states = array_column($result['suggestions'], 'state', 'path');

        $this->assertSame('directory', $states[$dir]);
        $this->assertSame('file', $states[$file]);
        $this->assertSame('missing', $states[$missing]);
    }

    public function testConfiguredPathsAreMarked(): void
    {
        $this->givenRepository([['backupPath' => '/srv/configured/']]);
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', ['/srv/configured', '/srv/other']),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);
        $configured = array_column($result['suggestions'], 'configured', 'path');

        $this->assertTrue($configured['/srv/configured']);
        $this->assertFalse($configured['/srv/other']);
    }

    public function testDumpFolderIsFiltered(): void
    {
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', [self::DUMP_FOLDER, '/srv/keep']),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertSame(['/srv/keep'], array_column($result['suggestions'], 'path'));
    }

    public function testUninitializedRestorePathStillFiltersDumpFolderWithoutError(): void
    {
        $this->givenRepository([], false);
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', [self::DUMP_FOLDER, '/srv/keep']),
        ]);
        $this->logger->expects($this->never())->method('error');

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertSame(['/srv/keep'], array_column($result['suggestions'], 'path'));
    }

    public function testListIsCappedAndFlagged(): void
    {
        $this->givenRepository();
        $paths = [];
        for ($i = 0; $i < 205; $i++) {
            $paths[] = '/srv/path-' . $i;
        }
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', $paths),
        ]);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertCount(BackupPathSuggestions::MAX_SUGGESTIONS, $result['suggestions']);
        $this->assertSame(200, BackupPathSuggestions::MAX_SUGGESTIONS);
        $this->assertTrue($result['truncated']);
    }

    public function testResticFailureReturnsEmptyListWithError(): void
    {
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willThrowException(
            new \RuntimeException('restic failed with ' . self::PASSWORD)
        );
        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertSame([], $result['suggestions']);
        $this->assertFalse($result['truncated']);
        $this->assertIsString($result['error']);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $result['error']);
    }

    public function testDumpPathFailureStillReturnsSuggestions(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getBackupPath')->willThrowException(new \RuntimeException('mkdir failed'));
        $this->logger->expects($this->atLeastOnce())->method('error');
        $suggestions = new BackupPathSuggestions(
            $this->logger,
            $this->backupRepository,
            $this->manageRepository,
            $settings,
            new Helper()
        );
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([
            $this->snapshot('2026-01-01T10:00:00Z', ['/srv/keep']),
        ]);

        $result = $suggestions->collect($this->repositoryId);

        $this->assertNull($result['error']);
        $this->assertSame(['/srv/keep'], array_column($result['suggestions'], 'path'));
    }

    public function testMissingRepositoryReturnsEmptyListWithError(): void
    {
        $this->backupRepository->method('getBackupRepositoryById')->willReturn(null);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertSame([], $result['suggestions']);
        $this->assertIsString($result['error']);
    }

    public function testRepositoryWithoutSnapshotsReturnsEmptyListWithoutError(): void
    {
        $this->givenRepository();
        $this->manageRepository->method('readSnapshots')->willReturn([]);

        $result = $this->suggestions->collect($this->repositoryId);

        $this->assertSame([], $result['suggestions']);
        $this->assertFalse($result['truncated']);
        $this->assertNull($result['error']);
    }
}
