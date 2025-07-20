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
namespace MuckiFacilityPlugin\Twig\Extension;

use Psr\Log\LoggerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\ImageConverter;

#[Package('core')]
class WebpLoaderExtension extends AbstractExtension
{
    public function __construct(
        private readonly LoggerInterface $logger,
        protected KernelInterface $kernel,
        protected ImageConverter $imageConverter
    )
    {}
    public function getFunctions(): array
    {
        return [
            new TwigFunction('muwa_getWebpUrl', $this->getUrlWebpImage(...), ['is_safe' => ['html']]),
        ];
    }

    public function getUrlWebpImage(string $filePath): ?string
    {
        try {
            return $this->imageConverter->getWebpAbsolutePath($filePath);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }

}
