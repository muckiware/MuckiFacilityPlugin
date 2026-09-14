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
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1787320672 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787320672;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        $columnCheck = $connection->fetchNumeric('
            SELECT count(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE
	            TABLE_NAME = \'muwa_backup_repository\'
                AND
                COLUMN_NAME = \'db_dump_path\'
        ');
        if(!empty($columnCheck) && (int) $columnCheck[0] === 0) {
            $connection->executeStatement('ALTER TABLE `muwa_backup_repository` ADD COLUMN `db_dump_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `restore_path`;');
        }
    }
}
