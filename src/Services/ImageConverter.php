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
namespace MuckiFacilityPlugin\Services;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Output\OutputInterface;
use WebPConvert\WebPConvert;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\Content\MediaRepository;

/**
 *
 */
class ImageConverter
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected ServicesCliOutput $servicesCliOutput,
        protected MediaRepository $mediaRepository
    )
    {}

    public function convertImageById(string $mediaId)
    {
        //https://sw66.ddev.site/media/1b/3f/3f/1751907539/Desktop Hintergrund 04.png?ts=1751907539
        //0197e5d395fc78cbac877fba3a05e716
        $media = $this->mediaRepository->getMediaRepositoryById($mediaId);
        if($media) {
            $this->convertImageToWebp($media->getPath());
        }
        $checker =1;
    }

    public function convertImageToWebp(string $imagePath)
    {
        $absoluteImagePath = $this->pluginSettings->getProjectPublicDir().'/'.$imagePath;
        if(is_readable($absoluteImagePath)) {

            $options = [];
            WebPConvert::convert($absoluteImagePath, $absoluteImagePath. '.webp', $options);
            $checker = 1;
        }
        $checker = 1;
    }
}
