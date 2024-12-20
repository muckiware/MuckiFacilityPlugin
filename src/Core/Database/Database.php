<?php

namespace MuckiFacilityPlugin\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

class Database
{
    public function __construct(
        protected LoggerInterface $logger,
        protected Connection $connection
    )
    {}

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
