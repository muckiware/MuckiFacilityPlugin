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
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;
use Shopware\Core\Framework\Context;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\DbTableCleanup as ServiceDbTableCleanup;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

#[AsCommand(
    name: 'muckiware:table:cleanup',
    description: 'Cleanup old database table items'
)]
class DbTableCleanup extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected ServiceDbTableCleanup $servicesDbTableCleanup,
        protected ServicesCliOutput $servicesCliOutput
    ) {

        parent::__construct();
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @internal
     */
    public function configure()
    {
        $this->setDescription('Cleanup old database table items');
        $this->addArgument('tableName',InputArgument::REQUIRED, 'Name of the table to cleanup');
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $executionStart = microtime(true);

        $this->servicesCliOutput->setOutput($output);
        $output->writeln( 'Starting executing cleanup table');
        $this->logger->info('Starting executing cleanup table', PluginDefaults::DEFAULT_LOGGER_CONFIG);

        $tableNameForCleanup = $this->checkInputForTableCleanupType($input);
        if($tableNameForCleanup) {
            $this->servicesDbTableCleanup->cleanupTable($tableNameForCleanup);
        } else {
            $output->writeln('Problem: No table name provided or invalid table name');
            $this->logger->error('No table name provided or invalid table name', PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return self::FAILURE;
        }

        $executionTime = microtime(true) - $executionStart;

        if($executionTime > 60) {
            $output->writeln('cleanup table DONE. [Execution: '.(number_format($executionTime/60,2)).' min]');
            $this->logger->info('cleanup cart DONE. [Execution: '.($executionTime/60).' min]', PluginDefaults::DEFAULT_LOGGER_CONFIG);
        } else {
            $output->writeln('cleanup table DONE. [Execution: '.number_format($executionTime, 3).' sec]');
            $this->logger->info('cleanup table DONE. [Execution: '.$executionTime.' sec]', PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return self::SUCCESS;
    }
}
