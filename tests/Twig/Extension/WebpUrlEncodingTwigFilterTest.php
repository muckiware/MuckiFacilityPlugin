<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\Twig\Extension;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\HelperMedia;
use MuckiFacilityPlugin\Entity\MediaLocationEntity;
use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\Twig\Extension\WebpUrlEncodingTwigFilter;
use MuckiFacilityPlugin\Services\ImageConverter;

class WebpUrlEncodingTwigFilterTest extends TestCase
{
   public function testEncodeWebpUrl(): void
   {
       $webpUrlEncodingTwigFilterInstance = $this->createWebpUrlEncodingTwigFilterInstance();
       $webpUrlEncodingTwigFilterInstance->encodeWebpUrl(TestCaseBaseDefaults::ENCODE_WEBP_URL);
   }

    private function createWebpUrlEncodingTwigFilterInstance(): WebpUrlEncodingTwigFilter
    {
        $webpUrlEncodingTwigFilter = new WebpUrlEncodingTwigFilter(
            $this->createMock(LoggerInterface::class),
            $this->createMock(KernelInterface::class),
            $this->createMock(ImageConverter::class)
        );

        return $webpUrlEncodingTwigFilter;
    }
}
