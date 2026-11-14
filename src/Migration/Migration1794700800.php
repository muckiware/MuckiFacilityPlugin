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
 * Recreates the foreign keys of the checks, snapshots and stats tables with ON DELETE CASCADE, so a
 * backup repository can be deleted together with its child rows. Only touches constraints whose
 * delete rule is not yet CASCADE, so the migration is safe to run more than once.
 */
final class Migration1794700800 extends MigrationStep
{
    private const FOREIGN_KEYS = [
        'muwa_backup_repository_checks' => 'fk.backup_repository.id',
        'muwa_backup_repository_snapshots' => 'fk.backup_repository.snapshots.id',
        'muwa_backup_repository_stats' => 'fk.backup_repository_stats.id',
    ];

    public function getCreationTimestamp(): int
    {
        return 1794700800;
    }

    public function update(Connection $connection): void
    {
        foreach (self::FOREIGN_KEYS as $table => $constraint) {
            $deleteRule = $connection->fetchOne(
                'SELECT `DELETE_RULE` FROM `INFORMATION_SCHEMA`.`REFERENTIAL_CONSTRAINTS`
                 WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                   AND `TABLE_NAME` = :table
                   AND `CONSTRAINT_NAME` = :constraint',
                ['table' => $table, 'constraint' => $constraint]
            );

            if ($deleteRule === false || strtoupper((string) $deleteRule) === 'CASCADE') {
                continue;
            }

            // MySQL rejects dropping and re-adding a constraint of the same name in one ALTER TABLE
            $connection->executeStatement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`;', $table, $constraint));
            $connection->executeStatement(sprintf(
                'ALTER TABLE `%1$s`
                    ADD CONSTRAINT `%2$s` FOREIGN KEY (`backup_repository_id`)
                        REFERENCES `muwa_backup_repository` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE;',
                $table,
                $constraint
            ));
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
