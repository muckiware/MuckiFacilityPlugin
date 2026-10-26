<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 */
namespace MuckiFacilityPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1793022892 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793022892;
    }

    public function update(Connection $connection): void
    {
        $columns = [
            'pre_backup_command_arguments' => 'pre_backup_command',
            'post_backup_command_arguments' => 'post_backup_command',
        ];

        foreach ($columns as $name => $after) {
            if ($this->columnExists($connection, 'muwa_backup_repository', $name)) {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE `muwa_backup_repository` MODIFY COLUMN `%s` TEXT NOT NULL;',
                    $name
                ));
                continue;
            }

            $connection->executeStatement(sprintf(
                "ALTER TABLE `muwa_backup_repository` ADD COLUMN `%s` TEXT NOT NULL AFTER `%s`;",
                $name,
                $after
            ));
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
