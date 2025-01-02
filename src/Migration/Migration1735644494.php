<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1735644494 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735644494;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `muwa_backup_repository_checks` (
              `id` binary(16) NOT NULL,
              `backup_repository_id` binary(16) NOT NULL,
              `check_status` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `created_at` datetime(3) NOT NULL,
              `updated_at` datetime(3) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `fk.backup_repository.id_idx` (`backup_repository_id`),
              CONSTRAINT `fk.backup_repository.id` FOREIGN KEY (`backup_repository_id`) REFERENCES `muwa_backup_repository` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ';
        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
