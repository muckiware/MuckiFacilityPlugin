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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationAssistant\Exception\ConverterNotFoundException;
use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\WebPConvert;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\HttpKernel\KernelInterface;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;
use League\Flysystem\FilesystemException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\CliOutput as ServicesCliOutput;
use MuckiFacilityPlugin\Services\Content\MediaRepository;
use MuckiFacilityPlugin\Entity\MediaLocationEntity;

/**
 *
 */
class ImageConverter
{
    public function __construct(
        protected LoggerInterface $logger,
        protected KernelInterface $kernel,
        protected PluginSettings $pluginSettings,
        protected PluginHelper $pluginHelper,
        protected ServicesCliOutput $servicesCliOutput,
        protected MediaRepository $mediaRepository,
        protected HelperMedia $helperMedia
    )
    {}

    public function convertImageById(string $mediaId)
    {
        //0197e5d395fc78cbac877fba3a05e716
        $media = $this->mediaRepository->getMediaRepositoryById($mediaId);
        if($media) {
            $this->convertImageToWebp($media->getPath());
        }
    }

    public function convertAllImage(Context $context)
    {
        $mediaIterator = $this->mediaRepository->getMediaRepository($context);
        $totalMediaCount = $mediaIterator->getTotal();
        if($this->servicesCliOutput->isCli()) {

            $this->servicesCliOutput->printCliOutputNewline('Found '.$totalMediaCount.' media items to convert to WebP format.');
            $progress = $this->servicesCliOutput->prepareProgress($totalMediaCount);
            $progressBar = $this->servicesCliOutput->prepareProgressBar($progress, $totalMediaCount);
            $this->servicesCliOutput->setProgressMessage('Media');

            $generateWebpImagesResults = $this->generateWebpImages($mediaIterator, $progress, $progressBar);
            $this->servicesCliOutput->getSymfonyStyle()->table(
                ['Action', 'Number of Media Entities to WebP format'],
                [
                    ['Generated', $generateWebpImagesResults['generated']],
                    ['Skipped', $generateWebpImagesResults['skipped']],
                    ['Errors', $generateWebpImagesResults['errored']],
                ]
            );

//            $generateWebpImagesResults = $this->generateWebpThumbnails($mediaIterator, $progress, $progressBar);
//            $this->servicesCliOutput->getSymfonyStyle()->table(
//                ['Action', 'Number of Thumbnail Entities to WebP format'],
//                [
//                    ['Generated', $generateWebpImagesResults['generated']],
//                    ['Skipped', $generateWebpImagesResults['skipped']],
//                    ['Errors', $generateWebpImagesResults['errored']],
//                ]
//            );
        }
    }

    public function convertImageToWebp(MediaLocationEntity $mediaLocation): bool
    {
        if(is_readable($mediaLocation->getAbsolutePathWebp())) {

            // The WebP image already exists, so we skip the conversion
            return false;
        }

        $options = [];
        try {
            WebPConvert::convert($mediaLocation->getAbsolutePathOrigin(), $mediaLocation->getAbsolutePathWebp(), $options);
        } catch (ConversionFailedException $e) {

            $this->logger->error('Error setting conversion options: '.$e->getMessage());
            throw new ConverterNotFoundException(
                \sprintf('Cannot convert image %s to WebP format. Error message: %s', $mediaLocation->getAbsolutePathOrigin(), $e->getMessage())
            );
        }

        return true;
    }

    /**
     * @throws FilesystemException
     */
    public function getWebpAbsolutePath(string $absoluteImagePath): ?string
    {
        $adapter = new LocalFilesystemAdapter($this->kernel->getProjectDir().'/public');
        $filesystem = new Filesystem($adapter);

        if($filesystem->fileExists($absoluteImagePath)) {

            $imageHasher = new ImageHash(new DifferenceHash());
            $imageHash = $imageHasher->hash($absoluteImagePath)->toHex();
            return $absoluteImagePath. '.'.$imageHash.'.webp';
        }

        return null;
    }

    private function generateWebpImages(RepositoryIterator $iterator, Progress $progress=null, ProgressBar $progressBar=null): array
    {
        $generated = 0;
        $skipped = 0;
        $errored = 0;
        $errors = [];

        while (($result = $iterator->fetch()) !== null) {

            /** @var MediaEntity $media */
            foreach ($result->getEntities() as $media) {

                $this->setProgressStatus($progress, $progressBar);
                $mediaLocation = $this->helperMedia->getMediaLocationByMedia(
                    $this->pluginSettings->getProjectPublicDir(),
                    $media
                );
                try {
                    if ($this->convertImageToWebp($mediaLocation)) {
                        ++$generated;
                    } else {
                        ++$skipped;
                    }
                } catch (\Throwable $e) {
                    ++$errored;
                    $errors[] = [\sprintf('Cannot process file %s (id: %s) due error: %s', $media->getFileName(), $media->getId(), $e->getMessage())];
                }
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'errored' => $errored,
            'errors' => $errors,
        ];
    }

    private function generateWebpThumbnails(RepositoryIterator $iterator, Progress $progress=null, ProgressBar $progressBar=null): array
    {
        $generated = 0;
        $skipped = 0;
        $errored = 0;
        $errors = [];

        while (($result = $iterator->fetch()) !== null) {

            /** @var MediaEntity $media */
            foreach ($result->getEntities() as $media) {

                $this->setProgressStatus($progress, $progressBar);
                try {
                    if ($this->convertImageToWebp($media->getPath())) {
                        ++$generated;
                    } else {
                        ++$skipped;
                    }
                } catch (\Throwable $e) {
                    ++$errored;
                    $errors[] = [\sprintf('Cannot process file %s (id: %s) due error: %s', $media->getFileName(), $media->getId(), $e->getMessage())];
                }
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'errored' => $errored,
            'errors' => $errors,
        ];
    }

    public function setProgressStatus(?Progress $progress, ?ProgressBar $progressBar)
    {
        if ($this->servicesCliOutput->isCli() && $progress && $progressBar) {

            if ($progress->getOffset() >= $progress->getTotal()) {
                $progressBar->setProgress($progress->getTotal());
            } else {
                $progressBar->advance();
                $progressBar->display();
            }
        }
    }
}
