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
class Migration1789628400 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789628400;
    }

    /**
     * @throws Exception
     */
    public function update(Connection $connection): void
    {
        // Chiffretext braucht mehr Platz als das Passwort: Base64 ueber Nonce (24 Byte),
        // MAC (16 Byte) und Klartext. 255 Zeichen reichen dafuer nicht verlaesslich.
        $connection->executeStatement('
            ALTER TABLE `muwa_backup_repository`
            MODIFY `repository_password` varchar(512) COLLATE utf8mb4_general_ci NOT NULL;
        ');

        // columnExists() kommt aus MigrationStep und fragt per SHOW COLUMNS ab — das ist
        // immer auf die aktive Datenbank bezogen. Eine eigene INFORMATION_SCHEMA-Abfrage
        // ohne TABLE_SCHEMA-Filter waere auf Servern mit mehreren Shops falsch.
        if ($this->columnExists($connection, 'muwa_backup_repository', 'password_source')) {
            return;
        }

        // Bestandszeilen enthalten Klartext und bekommen deshalb 'plain'. Angehoben wird per
        // `bin/console muckiware:backup:encrypt-passwords`.
        $connection->executeStatement('
            ALTER TABLE `muwa_backup_repository`
            ADD COLUMN `password_source` varchar(16) COLLATE utf8mb4_general_ci NOT NULL DEFAULT \'plain\'
            AFTER `repository_password`;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
