<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Database;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

use MuckiFacilityPlugin\Services\SettingsInterface;
class DatabaseHelper
{
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection,
        protected SettingsInterface $pluginSettings
    ) {}
    function columnExists(string $tableName, string $columnName): bool
    {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = :tableName
                AND COLUMN_NAME = :columnName
                AND TABLE_SCHEMA = DATABASE()
        SQL;

        try {
            $result = $this->connection->executeQuery($sql, [
                'tableName' => $tableName,
                'columnName' => $columnName,
            ]);

            return (int)$result->fetchOne() > 0;
        } catch (Exception $e) {
            $this->logger->error('Error checking if column exists: ' . $e->getMessage());
            return false;
        }
    }
}
