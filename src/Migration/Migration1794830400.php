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
 * S3 repository mirror: creates muwa_s3_connection, the mirror columns on muwa_backup_repository
 * (NULL mirror_s3_connection_id = no mirroring, no existing data changes) and the history table
 * muwa_backup_repository_uploads. Idempotent.
 */
final class Migration1794830400 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794830400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `muwa_s3_connection` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `provider` VARCHAR(32) NOT NULL,
                `endpoint` VARCHAR(512) NULL,
                `region` VARCHAR(64) NULL,
                `access_key_id` VARCHAR(255) NOT NULL,
                `secret_access_key` VARCHAR(512) NULL,
                `secret_source` VARCHAR(16) NOT NULL,
                `custom_fields` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        if (!$this->columnExists($connection, 'muwa_backup_repository', 'mirror_s3_connection_id')) {
            $connection->executeStatement('
                ALTER TABLE `muwa_backup_repository`
                    ADD COLUMN `mirror_s3_connection_id` BINARY(16) NULL DEFAULT NULL,
                    ADD CONSTRAINT `fk.muwa_backup_repository.mirror_s3_connection_id`
                        FOREIGN KEY (`mirror_s3_connection_id`)
                        REFERENCES `muwa_s3_connection` (`id`)
                        ON DELETE RESTRICT ON UPDATE CASCADE;
            ');
        }

        if (!$this->columnExists($connection, 'muwa_backup_repository', 'mirror_s3_bucket')) {
            $connection->executeStatement(
                'ALTER TABLE `muwa_backup_repository` ADD COLUMN `mirror_s3_bucket` VARCHAR(255) NULL DEFAULT NULL'
            );
        }

        if (!$this->columnExists($connection, 'muwa_backup_repository', 'mirror_s3_prefix')) {
            $connection->executeStatement(
                'ALTER TABLE `muwa_backup_repository` ADD COLUMN `mirror_s3_prefix` VARCHAR(1024) NULL DEFAULT NULL'
            );
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `muwa_backup_repository_uploads` (
                `id` BINARY(16) NOT NULL,
                `backup_repository_id` BINARY(16) NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `trigger_source` VARCHAR(16) NOT NULL,
                `bucket` VARCHAR(255) NULL,
                `prefix` VARCHAR(1024) NULL,
                `s3_connection_name` VARCHAR(255) NULL,
                `started_at` DATETIME(3) NULL,
                `finished_at` DATETIME(3) NULL,
                `files_uploaded` INT NULL,
                `files_deleted` INT NULL,
                `files_unchanged` INT NULL,
                `bytes_uploaded` BIGINT NULL,
                `message` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.muwa_backup_repository_uploads.repository_created` (`backup_repository_id`, `created_at`),
                CONSTRAINT `fk.muwa_backup_repository_uploads.backup_repository_id`
                    FOREIGN KEY (`backup_repository_id`)
                    REFERENCES `muwa_backup_repository` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
