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
class Migration1789541538 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789541538;
    }

    public function update(Connection $connection): void
    {
        $columnCheck = $connection->fetchNumeric('
            SELECT count(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE
	            TABLE_NAME = \'muwa_backup_repository_snapshots\'
                AND
                COLUMN_NAME = \'total_files_processed\'
        ');
        if(!empty($columnCheck) && (int) $columnCheck[0] === 0) {
            $connection->executeStatement('ALTER TABLE `muwa_backup_repository_snapshots` ADD COLUMN `total_files_processed` INT NULL DEFAULT NULL AFTER `size`;');
        }
    }
}
