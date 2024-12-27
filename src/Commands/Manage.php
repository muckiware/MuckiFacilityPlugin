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
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\Context;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\ManageRepository as ManageService;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Entity\CreateBackupEntity;

#[AsCommand(
    name: 'muckiware:facility:manage',
    description: 'Manage backups'
)]
class Manage extends Command
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
        $this->setDescription('This MuckiFacility plugin command for to mange backup repositories');
        $this->addArgument('backupRepositoryId',InputArgument::REQUIRED, 'Backup repository id');
        $this->addArgument('snapshotId',InputArgument::OPTIONAL, 'Backup snapshot id');
        $this->addOption('list', 'l', null, 'List all backup snapshots');
        $this->addOption('remove', 'rm', null, 'Remove backup snapshot by id');
        $this->addOption('forget', 'fg', null, 'Remove old backup snapshots by forget parameters');
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
        $output->writeln('Start to run backup');
        $inputBackupRepositoryId = $this->checkInputForBackupRepositoryId($input);
        if($this->pluginSettings->isEnabled() && $inputBackupRepositoryId) {

            if($input->getOption('list')) {

                $snapshot = $this->manageService->getSnapshots($inputBackupRepositoryId, false);
                $output->writeln($snapshot);
            }

            if($input->getOption('remove')) {

                $snapshotId = $this->checkInputForSnapshotId($input);
                if($snapshotId) {
                    $removeSnapshotResult = $this->manageService->removeSnapshotById(
                        $inputBackupRepositoryId,
                        $snapshotId,
                        false
                    );
                    $output->writeln($removeSnapshotResult);
                }
            }

            if($input->getOption('forget')) {

                $snapshot = $this->manageService->forgetSnapshots($inputBackupRepositoryId, false);
                $output->writeln($snapshot);
            }
        }

        return 0;
    }

    protected function checkInputForBackupRepositoryId(InputInterface $input): string
    {
        $backupRepositoryId = $input->getArgument('backupRepositoryId');
        if(
            $backupRepositoryId &&
            $backupRepositoryId !== '' &&
            Uuid::isValid($backupRepositoryId)
        ) {
            return $backupRepositoryId;
        }

        throw new \InvalidArgumentException('Invalid backup repository id');
    }

    protected function checkInputForSnapshotId(InputInterface $input): string
    {
        $snapshotId = $input->getArgument('snapshotId');
        if(
            $snapshotId &&
            $snapshotId !== ''
        ) {
            return $snapshotId;
        }

        throw new \InvalidArgumentException('Missing snapshot id');
    }
}
