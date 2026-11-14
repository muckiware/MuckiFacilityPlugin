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
 * Widens muwa_backup_repository_snapshots.paths (was VARCHAR(155)) to LONGTEXT, so long path lists
 * of imported snapshots fit. Only changes the column while it is not yet TEXT/LONGTEXT, so the
 * migration is safe to run more than once.
 */
final class Migration1794614400 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794614400;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'muwa_backup_repository_snapshots', 'paths')) {
            return;
        }

        $dataType = $connection->fetchOne(
            'SELECT `DATA_TYPE` FROM `INFORMATION_SCHEMA`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = :table
               AND `COLUMN_NAME` = :column',
            ['table' => 'muwa_backup_repository_snapshots', 'column' => 'paths']
        );

        if ($dataType === false || in_array(strtolower((string) $dataType), ['text', 'longtext'], true)) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `muwa_backup_repository_snapshots` MODIFY COLUMN `paths` LONGTEXT NULL DEFAULT NULL;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
