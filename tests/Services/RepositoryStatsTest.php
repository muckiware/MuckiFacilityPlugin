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

use League\Flysystem\UnableToListContents;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryStats;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\RepositoryStats;

class RepositoryStatsTest extends TestCase
{
    private function createBackupRepositoryMock(string $repositoryPath = '/tmp/repo'): BackupRepository
    {
        $entity = new BackupRepositoryEntity();
        $entity->setRepositoryPath($repositoryPath);

        $backupRepository = $this->createMock(BackupRepository::class);
        $backupRepository->method('getBackupRepositoryById')->willReturn($entity);

        return $backupRepository;
    }

    public function testCollectMapsCompleteResticOutput(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(
            json_encode(['total_size' => 4096, 'total_file_count' => 12, 'snapshots_count' => 3])
        );

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willReturn(8192);

        $checksEntity = new BackupRepositoryChecksEntity();
        $checksEntity->setCheckStatus('no errors were found');
        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn($checksEntity);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertSame(4096, $collected['totalSize'], 'total_size should be mapped to totalSize');
        static::assertSame(12, $collected['totalFileCount'], 'total_file_count should be mapped to totalFileCount');
        static::assertSame(3, $collected['snapshotsCount'], 'snapshots_count should be mapped to snapshotsCount');
        static::assertSame(8192, $collected['fileSystemSize'], 'directory size should be mapped to fileSystemSize');
        static::assertSame('no errors were found', $collected['checkStatus'], 'latest check status should be copied');
    }

    public function testCollectReturnsNullForMissingResticKeys(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(json_encode(['total_size' => 0]));

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willReturn(0);

        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn(null);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertSame(0, $collected['totalSize'], 'a present zero value must stay zero, not become null');
        static::assertNull($collected['totalFileCount'], 'missing total_file_count should be null');
        static::assertNull($collected['snapshotsCount'], 'missing snapshots_count should be null');
        static::assertNull($collected['checkStatus'], 'missing check should be null');
        static::assertSame(0, $collected['fileSystemSize'], 'a directory size of zero must stay zero');
    }

    public function testCollectKeepsResticValuesWhenDirectorySizeFails(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(
            json_encode(['total_size' => 4096, 'total_file_count' => 12, 'snapshots_count' => 3])
        );

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willThrowException(
            UnableToListContents::atLocation('/tmp/repo', true, new \RuntimeException('scan failed'))
        );

        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn(null);

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $this->createMock(BackupRepositoryStats::class),
            $pluginHelper
        );

        $collected = $repositoryStats->collect(Uuid::randomHex());

        static::assertNull($collected['fileSystemSize'], 'a failing directory scan should leave fileSystemSize null');
        static::assertSame(4096, $collected['totalSize'], 'restic values must survive a failing directory scan');
        static::assertSame(12, $collected['totalFileCount'], 'restic values must survive a failing directory scan');
    }

    public function testCollectAndSaveSwallowsExceptions(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willThrowException(
            new \Exception('Repository path does not exist: /tmp/repo')
        );

        $backupRepositoryStats = $this->createMock(BackupRepositoryStats::class);
        $backupRepositoryStats->expects(static::never())->method('saveNewStats');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::atLeastOnce())->method('error');

        $repositoryStats = new RepositoryStats(
            $logger,
            $manageService,
            $this->createBackupRepositoryMock(),
            $this->createMock(BackupRepositoryChecks::class),
            $backupRepositoryStats,
            $this->createMock(PluginHelper::class)
        );

        $result = $repositoryStats->collectAndSave(Uuid::randomHex());

        static::assertNull($result, 'collectAndSave must return null when it swallowed a throwable');
    }

    public function testCollectAndSaveSwallowsErrorsToo(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willThrowException(
            new \TypeError('Argument #1 ($value) must be of type string, int given')
        );

        $backupRepositoryStats = $this->createMock(BackupRepositoryStats::class);
        $backupRepositoryStats->expects(static::never())->method('saveNewStats');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::atLeastOnce())->method('error');

        $repositoryStats = new RepositoryStats(
            $logger,
            $manageService,
            $this->createBackupRepositoryMock(),
            $this->createMock(BackupRepositoryChecks::class),
            $backupRepositoryStats,
            $this->createMock(PluginHelper::class)
        );

        $result = $repositoryStats->collectAndSave(Uuid::randomHex());

        static::assertNull($result, 'collectAndSave must return null when it swallowed an \Error too');
    }

    public function testCollectAndSaveReturnsTheCollectedDataOnSuccess(): void
    {
        $manageService = $this->createMock(ManageService::class);
        $manageService->method('getRepositoryStatsById')->willReturn(
            json_encode(['total_size' => 4096, 'total_file_count' => 12, 'snapshots_count' => 3])
        );

        $pluginHelper = $this->createMock(PluginHelper::class);
        $pluginHelper->method('getDirectorySize')->willReturn(8192);

        $checksEntity = new BackupRepositoryChecksEntity();
        $checksEntity->setCheckStatus('no errors were found');
        $backupRepositoryChecks = $this->createMock(BackupRepositoryChecks::class);
        $backupRepositoryChecks->method('getLatestChecksByRepositoryId')->willReturn($checksEntity);

        $backupRepositoryStats = $this->createMock(BackupRepositoryStats::class);
        $backupRepositoryStats->expects(static::once())->method('saveNewStats');

        $repositoryStats = new RepositoryStats(
            $this->createMock(LoggerInterface::class),
            $manageService,
            $this->createBackupRepositoryMock(),
            $backupRepositoryChecks,
            $backupRepositoryStats,
            $pluginHelper
        );

        $backupRepositoryId = Uuid::randomHex();
        $expected = $repositoryStats->collect($backupRepositoryId);
        $result = $repositoryStats->collectAndSave($backupRepositoryId);

        static::assertSame($expected, $result, 'collectAndSave must return the same data collect() produces');
        static::assertSame(
            ['totalSize', 'totalFileCount', 'snapshotsCount', 'fileSystemSize', 'checkStatus'],
            array_keys($result ?? []),
            'the returned array must carry the same five keys as collect()'
        );
    }
}
