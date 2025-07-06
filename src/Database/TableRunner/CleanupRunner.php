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
namespace MuckiFacilityPlugin\Database\TableRunner;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Database\DatabaseHelper;
use MuckiFacilityPlugin\Database\TableCleanupInterface;
use MuckiFacilityPlugin\Services\SettingsInterface;
use MuckiFacilityPlugin\Services\CliOutput;

class CleanupRunner
{
    public function __construct(
        LoggerInterface $logger,
        Connection $connection,
        SettingsInterface $pluginSettings,
        CliOutput $cliOutput,
        DatabaseHelper $databaseHelper
    )
    {}

    public function copyTableItems(string $sourceTableName, string $targetTableName): void
    {
        $this->cliOutput->writeNewLineCliOutput('Copy '.$sourceTableName.' items into '.$targetTableName);

        $sql = '
            INSERT INTO `' . $targetTableName . '`
            SELECT * FROM `' . $sourceTableName . '`;
        ';

        try {
            $this->connection->executeStatement($sql);
        } catch (Exception $e) {

            $this->logger->error(print_r($e, true), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new Exception('copy of '.$sourceTableName.' items into '.$targetTableName.' table not possible');
        }

        $this->cliOutput->writeSameLineCliOutput('...done');
    }
}
