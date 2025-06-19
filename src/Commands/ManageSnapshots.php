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
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Shopware\Core\Framework\Context;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

#[AsCommand(
    name: 'muckiware:backup:snapshots',
    description: 'Get a list of snapshots of a backup repository'
)]
class ManageSnapshots extends Command
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
        $this->addOption('remove', 'r', InputOption::VALUE_OPTIONAL, 'Remove snapshot by ids of current repository id')->addArgument('snapshotIds',InputArgument::OPTIONAL, 'snapshot ids to remove, comma separated');
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

            $snapshotIds = $this->checkInputForRemoveSnapshotIds($input);
            if(!empty($snapshotIds)) {

                $outputExecution = $this->manageService->removeSnapshotByIds(
                    $inputBackupRepositoryId,
                    $snapshotIds,
                    false
                );
            } else {
                $outputExecution = $this->manageService->getSnapshots($inputBackupRepositoryId, false);
            }

            $this->manageService->saveSnapshots($inputBackupRepositoryId);
            $output->writeln($outputExecution);
        }

        return 0;
    }

    protected function checkInputForBackupRepositoryId(InputInterface $input): string
    {
        $backupRepositoryId = $input->getArgument('backupRepositoryId');
        if($backupRepositoryId !== '' && Uuid::isValid($backupRepositoryId)) {
            return $backupRepositoryId;
        }

        throw new \InvalidArgumentException('Invalid or missing backup repository id');
    }

    protected function checkInputForRemoveSnapshotIds(InputInterface $input): array
    {
        $remove = $input->getOption('remove');
        if(!$remove) {
            return [];
        }

        $validSnapshotIds = [];
        $snapshotIds = array_unique(explode(',', $remove), SORT_STRING);
        foreach ($snapshotIds as $snapshotId) {
            if($this->pluginHelper->isValidShortId($snapshotId)) {
                $validSnapshotIds[] = $snapshotId;
            }
        }

        return $validSnapshotIds;
    }
}
