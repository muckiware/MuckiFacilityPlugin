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
 * Storage of the single-use dump download tokens (Services\RestoreDownloadToken). The layout is the
 * one Symfony's DoctrineDbalAdapter expects. Created here instead of letting the adapter create it
 * on first write, because that would run DDL in the middle of an API request.
 */
final class Migration1794744000 extends MigrationStep
{
    public const TABLE = 'muwa_restore_download_token';

    public function getCreationTimestamp(): int
    {
        return 1794744000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement($this->sql());
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function sql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `item_id` VARBINARY(255) NOT NULL,
            `item_data` MEDIUMBLOB NOT NULL,
            `item_lifetime` INT UNSIGNED NULL,
            `item_time` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
    }
}
