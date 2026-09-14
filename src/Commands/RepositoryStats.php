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
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\RepositoryStats as RepositoryStatsService;

#[AsCommand(
    name: 'muckiware:repository:stats',
    description: 'Collect and persist the current status of a backup repository'
)]
class RepositoryStats extends Command
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected RepositoryStatsService $repositoryStats
    )
    {
        parent::__construct();
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

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
        $this->addArgument('backupRepositoryId', InputArgument::REQUIRED, 'Backup repository id');
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupRepositoryId = $this->checkInputForBackupRepositoryId($input);
        if (!$this->pluginSettings->isEnabled()) {

            $output->writeln('MuckiFacilityPlugin is not enabled');
            return 0;
        }

        $collected = $this->repositoryStats->collectAndSave($backupRepositoryId);
        if ($collected === null) {

            $output->writeln('Could not collect the repository status, see the log for details');
            return 1;
        }

        foreach ($collected as $label => $value) {
            $output->writeln($label.': '.($value ?? '-'));
        }

        if ($collected['totalSize'] === null && $collected['totalFileCount'] === null && $collected['snapshotsCount'] === null) {
            $output->writeln('Could not read the restic statistics, see the log for details');
            return 1;
        }

        return 0;
    }

    protected function checkInputForBackupRepositoryId(InputInterface $input): string
    {
        $backupRepositoryId = $input->getArgument('backupRepositoryId');
        if ($backupRepositoryId !== '' && Uuid::isValid($backupRepositoryId)) {
            return $backupRepositoryId;
        }

        throw new \InvalidArgumentException('Invalid or missing backup repository id');
    }
}
