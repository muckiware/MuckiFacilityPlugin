<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
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
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected PluginSettings $pluginSettings,
        protected EntityRepository $backupFileSnapshotsRepository
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
                'size' => \ByteUnits\Binary::bytes($snapshot['summary']['total_bytes_processed'])->format(),
                'createdAt' => \DateTime::createFromFormat(
                    'Y-m-d\TH:i:s.uP',
                    substr_replace($snapshot['time'],
                        '',
                        26,
                        3
                    )
                )
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

