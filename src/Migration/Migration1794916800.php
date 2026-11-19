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
 * Adds muwa_database_connection.dump_options (selected mysqldump options, see
 * MuckiFacilityPlugin\Core\MysqlDumpOption). NULL keeps the previous behaviour (--no-tablespaces).
 */
final class Migration1794916800 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794916800;
    }

    public function update(Connection $connection): void
    {
        if ($this->columnExists($connection, 'muwa_database_connection', 'dump_options')) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `muwa_database_connection` ADD COLUMN `dump_options` JSON NULL AFTER `password_source`;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
