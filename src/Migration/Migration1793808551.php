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

/**
 * Makes the optional pre/post backup command columns nullable (DEFAULT NULL). Only columns that
 * are still NOT NULL are changed, so the migration is safe to run more than once.
 */
final class Migration1793808551 extends MigrationStep
{
    public const COLUMNS = [
        'pre_backup_command',
        'pre_backup_fail_on_error',
        'post_backup_command',
        'post_backup_fail_on_error',
    ];

    public function getCreationTimestamp(): int
    {
        return 1793808551;
    }

    public function update(Connection $connection): void
    {
        foreach (self::COLUMNS as $name) {
            if (!$this->columnExists($connection, 'muwa_backup_repository', $name)) {
                continue;
            }

            $column = $connection->fetchAssociative(
                'SELECT `COLUMN_TYPE`, `IS_NULLABLE` FROM `INFORMATION_SCHEMA`.`COLUMNS`
                 WHERE `TABLE_SCHEMA` = DATABASE()
                   AND `TABLE_NAME` = :table
                   AND `COLUMN_NAME` = :column',
                ['table' => 'muwa_backup_repository', 'column' => $name]
            );

            if ($column === false || strtoupper((string) $column['IS_NULLABLE']) !== 'NO') {
                continue;
            }

            $connection->executeStatement(sprintf(
                'ALTER TABLE `muwa_backup_repository` MODIFY COLUMN `%s` %s NULL DEFAULT NULL;',
                $name,
                $column['COLUMN_TYPE']
            ));
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
