<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use MuckiRestic\Entity\Result\ResultEntity;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;

#[AsCommand(
    name: 'muckiware:db:dump',
    description: 'Creates a dump of the database'
)]
class Dump extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected BackupService $backupService,
        protected PluginHelper $pluginHelper
    )
    {
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
     * @return ContainerInterface|null
     */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * @internal
     */
    public function configure(): void
    {
        $this->setDescription(
            'Which kind of backup do you want to create? As single dump file of a separate dump file for each table?'
        );
        $this->addArgument('backupType',InputArgument::OPTIONAL, 'Type of backup');
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
        $output->writeln('Start to create database dump');
        $inputBackupType = $this->checkInputForBackupType($input);
        if($this->pluginSettings->isEnabled() && $inputBackupType) {

            $createBackup = new CreateBackupEntity();
            $createBackup->setBackupType($inputBackupType);

            $this->backupService->createBackup($createBackup);
        }

        return 0;
    }
}
