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
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Services\ImageConverter;

#[AsCommand(
    name: 'muckiware:convert:singleWebp',
    description: 'Convert images to WebP format'
)]
class ConvertSingleWebp extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected BackupService $backupService,
        protected PluginHelper $pluginHelper,
        protected ImageConverter $imageConverter
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
            'Convert images to WebP format'
        );
        $this->addArgument('mediaId',InputArgument::REQUIRED, 'Id of the image to convert');
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
        $output->writeln('Start to convert images to WebP format');

        $inputMediaId = $this->checkInputForMediaId($input);
        if($this->pluginSettings->isEnabled() && $inputMediaId) {
            $this->imageConverter->convertImageById($inputMediaId);
        }

        return 0;
    }
}
