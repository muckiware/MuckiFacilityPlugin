<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1735826311 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735826311;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `muwa_backup_repository_snapshots` (
              `id` binary(16) NOT NULL,
              `backup_repository_id` binary(16) NOT NULL,
              `snapshot_id` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
              `snapshot_short_id` varchar(45) COLLATE utf8mb4_general_ci NOT NULL,
              `paths` varchar(155) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `size` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `created_at` datetime(3) NOT NULL,
              `updated_at` datetime(3) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `fk.backup_repository.id_idx` (`backup_repository_id`),
              CONSTRAINT `fk.backup_repository.snapshots.id` FOREIGN KEY (`backup_repository_id`) REFERENCES `muwa_backup_repository` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ';
        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
