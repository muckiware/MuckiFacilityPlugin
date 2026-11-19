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
 * Adds muwa_backup_repository_stats.backup_duration: runtime of the whole backup run in seconds,
 * successful or not. NULL for stats collected outside a backup run (CLI, snapshot removal, takeover).
 */
final class Migration1795093023 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795093023;
    }

    public function update(Connection $connection): void
    {
        if ($this->columnExists($connection, 'muwa_backup_repository_stats', 'backup_duration')) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `muwa_backup_repository_stats` ADD COLUMN `backup_duration` INT UNSIGNED NULL AFTER `check_status`;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
