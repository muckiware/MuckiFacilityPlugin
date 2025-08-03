<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Subscriber;

use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[Package('discovery')]
class MediaLoadedSubscriber extends Struct
{
    public function unserialize(EntityLoadedEvent $event): void
    {
        /** @var MediaEntity $media */
        foreach ($event->getEntities() as $media) {
            if ($media->getMediaTypeRaw()) {
                $media->setMediaType(unserialize($media->getMediaTypeRaw()));
            }

            $thumbnails = match (true) {
                $media->getThumbnailsRo() !== null => unserialize($media->getThumbnailsRo()),
                default => new MediaThumbnailCollection(),
            };

            $media->addExtension('webps', new ArrayEntity(['id' => $media->getId(), 'thumbnails' => $thumbnails]));
            $media->setExtensions($media->getExtensions());
        }
    }
}
