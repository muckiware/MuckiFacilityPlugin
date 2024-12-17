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
use MuckiFacilityPlugin\Services\Backup as BackupService;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

#[AsCommand(
    name: 'muckiware:facility:backup',
    description: 'Create backups'
)]
class Backup extends Command
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
        $this->setDescription('This Muckilog plugin command for to send logger events by email');
        $this->addArgument('backupType',InputArgument::REQUIRED, 'Type of backup');
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
        $inputBackupType = $this->checkInputForBackupType($input);
        if($this->pluginSettings->isEnabled() && $inputBackupType) {
            $this->backupService->createBackup($inputBackupType);
        }
        $output->writeln('Backup is done');

        return 0;
    }

    protected function checkInputForBackupType(InputInterface $input): string
    {
        $backupTypeInput = $input->getArgument('backupType');
        if(
            $backupTypeInput &&
            $backupTypeInput !== '' &&
            $this->pluginHelper->checkBackupTypByInput($backupTypeInput)
        ) {
            return $backupTypeInput;
        }

        throw new \InvalidArgumentException('Invalid backup type');
    }
}
