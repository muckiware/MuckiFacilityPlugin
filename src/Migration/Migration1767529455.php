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
 * @internal
 */
class Migration1767529455 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767529455;
    }

    public function update(Connection $connection): void
    {
        if ($this->columnExists($connection, 'muwa_backup_repository_snapshots', 'hostname')) {
            return;
        }

        $connection->executeStatement('ALTER TABLE `muwa_backup_repository_snapshots` ADD COLUMN `hostname` VARCHAR(128) NULL DEFAULT NULL AFTER `paths`;');
    }
}
