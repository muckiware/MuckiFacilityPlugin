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
namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates muwa_database_connection and the optional link muwa_backup_repository.database_connection_id.
 * NULL means the standard connection (DATABASE_URL); no existing data is changed. Idempotent.
 */
final class Migration1794470400 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794470400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `muwa_database_connection` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `type` VARCHAR(32) NOT NULL,
                `host` VARCHAR(255) NOT NULL,
                `port` INT NULL,
                `database_name` VARCHAR(255) NOT NULL,
                `username` VARCHAR(255) NOT NULL,
                `password` VARCHAR(512) NOT NULL,
                `password_source` VARCHAR(16) NOT NULL,
                `custom_fields` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!$this->columnExists($connection, 'muwa_backup_repository', 'database_connection_id')) {
            $connection->executeStatement('
                ALTER TABLE `muwa_backup_repository`
                    ADD COLUMN `database_connection_id` BINARY(16) NULL DEFAULT NULL,
                    ADD CONSTRAINT `fk.muwa_backup_repository.database_connection_id`
                        FOREIGN KEY (`database_connection_id`)
                        REFERENCES `muwa_database_connection` (`id`)
                        ON DELETE RESTRICT ON UPDATE CASCADE;
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
