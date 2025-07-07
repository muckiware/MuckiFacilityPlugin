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

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;

/**
 *
 */
class ImageConverter
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected ServicesCliOutput $servicesCliOutput
    )
    {}

    public function convertImageById(string $mediaId)
    {
        $checker =1;
    }
}
