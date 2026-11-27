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
 * Adds muwa_backup_repository.dismissed_path_suggestions: JSON list of snapshot paths the user
 * dismissed in the backup path suggestions of a taken-over repository. NULL or [] = nothing dismissed.
 */
final class Migration1795774296 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795774296;
    }

    public function update(Connection $connection): void
    {
        if ($this->columnExists($connection, 'muwa_backup_repository', 'dismissed_path_suggestions')) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `muwa_backup_repository` ADD COLUMN `dismissed_path_suggestions` JSON NULL AFTER `backup_paths`;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
