<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1792800000 extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1792800000; }
    public function update(Connection $connection): void { $connection->executeStatement($this->sql()); }
    public function updateDestructive(Connection $connection): void {}
    private function sql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_backup_schedule` (
 `id` BINARY(16) NOT NULL,
 `backup_repository_id` BINARY(16) NOT NULL,
 `enabled` TINYINT(1) NOT NULL DEFAULT 0,
 `cron_expression` VARCHAR(255) NOT NULL,
 `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
 `status` VARCHAR(16) NOT NULL DEFAULT 'inactive',
 `last_execution_time` DATETIME(3) NULL,
 `next_execution_time` DATETIME(3) NULL,
 `last_error` LONGTEXT NULL,
 `run_token` VARCHAR(36) NULL,
 `created_at` DATETIME(3) NOT NULL,
 `updated_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uniq.muwa_backup_schedule.repository_id` (`backup_repository_id`),
 KEY `idx.muwa_backup_schedule.due` (`enabled`, `next_execution_time`),
 CONSTRAINT `fk.muwa_backup_schedule.repository_id` FOREIGN KEY (`backup_repository_id`)
 REFERENCES `muwa_backup_repository` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }
}
