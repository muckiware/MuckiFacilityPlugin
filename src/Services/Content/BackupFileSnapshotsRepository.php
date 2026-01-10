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
     * @param string $backupRepositoryId
     * @param array<mixed> $snapshots
     * @return void
     */
    public function createNewSnapshots(string $backupRepositoryId, array $snapshots): void
    {
        $data = array();
        foreach ($snapshots as $snapshot) {

            $data[] = [
                'id' => Uuid::randomHex(),
                'backupRepositoryId' => $backupRepositoryId,
                'snapshotId' => $snapshot['id'],
                'snapshotShortId' => $snapshot['short_id'],
                'paths' => implode(',', $snapshot['paths']),
                'hostname' => $snapshot['hostname'],
                'size' => \ByteUnits\Binary::bytes($snapshot['summary']['total_bytes_processed'])->asMetric()->format(),
                'createdAt' => $this->pluginHelper->createDateTimeFromString($snapshot['time'])
            ];
        }

        if(!empty($data)) {

            $this->logger->debug('saveSnapshots '. print_r($data, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->backupFileSnapshotsRepository->create($data, Context::createDefaultContext());
        }
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

