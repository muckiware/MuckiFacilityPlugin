<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Twig\Extension;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\ImageConverter;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

#[Package('framework')]
class WebpUrlEncodingTwigFilter extends AbstractExtension
{
    public function __construct(
        private readonly LoggerInterface $logger,
        protected KernelInterface $kernel,
        protected ImageConverter $imageConverter,
        protected PluginSettings $pluginSettings
    )
    {}
    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('muwa_encode_webp_url', $this->encodeWebpUrl(...)),
            new TwigFilter('muwa_encode_media_webp_url', $this->encodeMediaWebpUrl(...))
        ];
    }

    public function encodeWebpUrl(?string $mediaUrlInput, bool $noCheckWebp=false): ?string
    {
        if ($mediaUrlInput === null) {
            return null;
        }

        $urlInfo = parse_url($mediaUrlInput);
        if (!\is_array($urlInfo)) {
            return null;
        }

        $segments = array_filter(explode('/', $urlInfo['path'] ?? ''), fn($v) => $v !== '');

        foreach ($segments as $index => $segment) {
            $segments[$index] = rawurlencode($segment);
        }

        $path = implode('/', $segments);
        if (isset($urlInfo['query'])) {
            $path .= "?{$urlInfo['query']}";
        }

        if(!$noCheckWebp) {

            $webpImagesPath = $this->checkWebpImagesPath(explode('?', $path)[0]);
            if($webpImagesPath) {

                if (isset($urlInfo['query'])) {
                    $path = $webpImagesPath."?{$urlInfo['query']}";
                } else {
                    $path = $webpImagesPath;
                }
            }
        }

        $encodedPath = '';

        if (isset($urlInfo['scheme'])) {
            $encodedPath = "{$urlInfo['scheme']}://";
        }

        if (isset($urlInfo['host'])) {
            $encodedPath .= "{$urlInfo['host']}";
        }

        if (isset($urlInfo['port'])) {
            $encodedPath .= ":{$urlInfo['port']}";
        }

        return $encodedPath.'/'.$path;
    }

    public function encodeMediaWebpUrl(?MediaEntity $media): ?string
    {
        if ($media === null || !$media->hasFile()) {
            return null;
        }

        $webpImagesUrl = $this->checkWebpImagesPath($media->getPath());
        if($webpImagesUrl) {
            return $this->encodeWebpUrl($webpImagesUrl, true);
        }
        return $this->encodeWebpUrl($media->getUrl());
    }

    public function checkWebpImagesPath(string $filePath): ?string
    {
        try {

            $webpAbsolutePath = $this->imageConverter->getWebpPath($filePath);
            if($webpAbsolutePath) {
                return $webpAbsolutePath;
            }
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        return null;
    }
}
