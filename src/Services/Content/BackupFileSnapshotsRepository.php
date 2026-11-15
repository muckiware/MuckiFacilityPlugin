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
namespace MuckiFacilityPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

/**
 *
 */
class BackupFileSnapshotsRepository
{
    /**
     * @param LoggerInterface $logger
     * @param Connection $connection
     * @param PluginSettings $pluginSettings
     * @param EntityRepository $backupFileSnapshotsRepository
     * @param PluginHelper $pluginHelper
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupFileSnapshotsRepository,
        protected PluginHelper $pluginHelper
    )
    {}

    /**
     * Writes the given restic snapshots. Snapshots without an id are skipped and logged, missing
     * optional fields (summary, hostname, paths, short_id) never abort the import. A failing DAL
     * write is not caught.
     *
     * @param string $backupRepositoryId
     * @param array<mixed> $snapshots
     * @return int number of written rows
     */
    public function createNewSnapshots(string $backupRepositoryId, array $snapshots): int
    {
        $data = array();
        foreach ($snapshots as $snapshot) {

            $payload = $this->createSnapshotPayload($backupRepositoryId, (array) $snapshot);
            if ($payload === null) {
                $this->logger->warning(
                    'Snapshot without id skipped during import',
                    PluginDefaults::DEFAULT_LOGGER_CONFIG
                );
                continue;
            }

            $data[] = $payload;
        }

        if (empty($data)) {
            return 0;
        }

        $this->logger->debug('saveSnapshots '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->backupFileSnapshotsRepository->create($data, Context::createDefaultContext());

        return count($data);
    }

    /**
     * @param string $backupRepositoryId
     * @param array<mixed> $snapshot
     * @return array<string, mixed>|null null when the snapshot has no id
     */
    protected function createSnapshotPayload(string $backupRepositoryId, array $snapshot): ?array
    {
        $snapshotId = $snapshot['id'] ?? null;
        if (!is_string($snapshotId) || $snapshotId === '') {
            return null;
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $bytes = $summary['total_bytes_processed'] ?? null;
        $files = $summary['total_files_processed'] ?? null;
        $time = $snapshot['time'] ?? null;

        return [
            'id' => Uuid::randomHex(),
            'backupRepositoryId' => $backupRepositoryId,
            'snapshotId' => $snapshotId,
            'snapshotShortId' => (string) ($snapshot['short_id'] ?? substr($snapshotId, 0, 8)),
            'paths' => is_array($snapshot['paths'] ?? null) ? implode(',', $snapshot['paths']) : '',
            // snapshots.hostname is varchar(128): longer values are truncated instead of failing the insert
            'hostname' => mb_substr((string) ($snapshot['hostname'] ?? ''), 0, 128),
            'size' => is_numeric($bytes) ? \ByteUnits\Binary::bytes($bytes)->asMetric()->format() : null,
            'totalFilesProcessed' => is_numeric($files) ? (int) $files : null,
            'createdAt' => is_string($time) ? $this->pluginHelper->createDateTimeFromString($time) : null
        ];
    }

    /**
     * @param string $backupRepositoryId
     * @return void
     */
    public function removeOldSnapshots(string $backupRepositoryId): void
    {
        $sql = '
            DELETE FROM 
                `muwa_backup_repository_snapshots` 
            WHERE 
                `backup_repository_id` = :backupRepositoryId;
        ';

        try {

            $this->logger->debug('removeOldSnapshots '. $backupRepositoryId, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->logger->debug('remove statement '. $sql, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->connection->executeStatement(
                $sql,
                ['backupRepositoryId' => Uuid::fromHexToBytes($backupRepositoryId)]
            );
        } catch (Exception $e) {
            $this->logger->error('Problem to delete snapshots', PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }
    }
}

