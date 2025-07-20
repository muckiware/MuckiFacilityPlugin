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
use Shopware\Core\Content\Media\MediaEntity;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Entity\MediaLocationEntity;

class HelperMedia
{

    public function getMediaLocationByMedia(string $workingPath, MediaEntity $media): MediaLocationEntity
    {
        $absolutePathOrigin = $workingPath.'/'.$media->getPath();
        $originImageHash = $this->getImageHashByAbsolutePath($absolutePathOrigin);
        $webpExtension = '.'.$originImageHash.'.webp';

        $mediaLocation = new MediaLocationEntity();
        $mediaLocation->setMediaId($media->getId());
        $mediaLocation->setImageHash($originImageHash);
        $mediaLocation->setUrlOrigin($media->getUrl());
        $mediaLocation->setUrlWebp($media->getUrl().$webpExtension );
        $mediaLocation->setAbsolutePathOrigin($absolutePathOrigin);
        $mediaLocation->setAbsolutePathWebp($absolutePathOrigin.$webpExtension );
        $mediaLocation->setRelativePathOrigin($media->getPath());
        $mediaLocation->setRelativePathWebp($media->getPath().$webpExtension );

        return $mediaLocation;
    }

    public function getImageHashByAbsolutePath(string $absoluteImagePath): ?string
    {
        if(is_readable($absoluteImagePath)) {

            $imageHasher = new ImageHash(new DifferenceHash());
            return $imageHasher->hash($absoluteImagePath)->toHex();
        }

        return null;
    }
}
