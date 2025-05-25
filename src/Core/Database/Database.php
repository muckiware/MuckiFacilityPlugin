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
namespace MuckiFacilityPlugin\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

/**
 *
 */
class Database
{
    /**
     * @param LoggerInterface $logger
     * @param Connection $connection
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection
    )
    {}

    /**
     * @return array<string>|null
     */
    public function getListOfAllTables(): ?array
    {
        $results = array();
        $tables = array();
        try {
            $results = $this->connection->executeQuery('SHOW TABLES;')->fetchAllAssociative();
        } catch (Exception $e) {
            $this->logger->error('Problem to remove old cart items', PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        foreach ($results as $key => $value) {
            $tables[$key] = array_values($value)[0];
        }

        return $tables;
    }
}
