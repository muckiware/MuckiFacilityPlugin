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

final class Migration1792936196 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1792936196;
    }

    public function update(Connection $connection): void
    {
        $columns = [
            'pre_backup_command_type' => "VARCHAR(16) NOT NULL DEFAULT 'none'",
            'pre_backup_command' => "VARCHAR(4096) NOT NULL DEFAULT ''",
            'pre_backup_fail_on_error' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'post_backup_command_type' => "VARCHAR(16) NOT NULL DEFAULT 'none'",
            'post_backup_command' => "VARCHAR(4096) NOT NULL DEFAULT ''",
            'post_backup_fail_on_error' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->columnExists($connection, 'muwa_backup_repository', $name)) {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE `muwa_backup_repository` ADD COLUMN `%s` %s;',
                    $name,
                    $definition
                ));
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
