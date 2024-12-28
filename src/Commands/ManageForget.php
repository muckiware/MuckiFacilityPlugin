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

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

#[AsCommand(
    name: 'muckiware:backup:forget',
    description: 'Forget snapshots of a backup repository by forget parameters'
)]
class ManageForget extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected ManageService $manageService,
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
        $inputBackupRepositoryId = $this->checkInputForBackupRepositoryId($input);
        if($this->pluginSettings->isEnabled() && $inputBackupRepositoryId) {

            $snapshot = $this->manageService->forgetSnapshots($inputBackupRepositoryId, false);
            $output->writeln($snapshot);
        }

        return 0;
    }
}
