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
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

#[AsCommand(
    name: 'muckiware:backup:create',
    description: 'Create a backup into existing repository'
)]
class BackupCreate extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected BackupService $backupService,
        protected PluginHelper $pluginHelper,
        protected ServicesCliOutput $servicesCliOutput
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
        $this->setDescription('Id for the existing backup repository');
        $this->addArgument('backupRepositoryId',InputArgument::REQUIRED, 'Backup repository id');
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
        $this->servicesCliOutput->setOutput($output);
        $this->servicesCliOutput->setIsCli(true);

        $output->writeln('Start to run backup');
        $backupRepositoryId = $this->checkInputForBackupRepositoryId($input);
        if($this->pluginSettings->isEnabled() && $backupRepositoryId) {

            $createBackup = $this->backupService->prepareCreateBackup($backupRepositoryId);
            $this->backupService->createBackup($createBackup, false);

            /** @var ResultEntity $result */
            foreach ($this->backupService->getAllResults() as $result) {
                $output->writeln($result->getOutput());
            }
        }
        $output->writeln('Backup is done');

        return 0;
    }
}
