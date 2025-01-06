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
class Migration1735638755 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1735638755;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `muwa_backup_repository` (
              `id` binary(16) NOT NULL,
              `active` tinyint(1) DEFAULT \'0\',
              `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
              `type` varchar(45) COLLATE utf8mb4_general_ci NOT NULL,
              `repository_path` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
              `repository_password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
              `restore_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `backup_paths` longtext COLLATE utf8mb4_general_ci,
              `forget_daily` int DEFAULT NULL,
              `forget_weekly` int DEFAULT NULL,
              `forget_monthly` int DEFAULT NULL,
              `forget_yearly` int DEFAULT NULL,
              `created_at` datetime(3) NOT NULL,
              `updated_at` datetime(3) DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    
        ';
        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
