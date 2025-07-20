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
namespace MuckiFacilityPlugin\Storefront\Framework\Twig;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Shopware\Storefront\Framework\Twig\ThumbnailExtension;

use MuckiFacilityPlugin\Storefront\Framework\Twig\TokenParser\WebPTokenParser;

#[Package('framework')]
class WebPExtension extends AbstractExtension
{
    public function __construct(
        protected ThumbnailExtension $thumbnailExtension,
        private readonly TemplateFinder $finder
    )
    {}

    public function getTokenParsers(): array
    {
        return [
            new WebPTokenParser(),
        ];
    }

    public function getFinder(): TemplateFinder
    {
        return $this->finder;
    }
}
