<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Services\HelperMedia;
use MuckiFacilityPlugin\Entity\MediaLocationEntity;
use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;

class HelperMediaTest extends TestCase
{
    public function testCheckHelperFunction(): void
    {
        $workingPath = TestCaseBaseDefaults::getPluginPath().'/tests/TestCaseBase/public';
        $swMediaEntity = $this->createSWMediaEntity();
        $helperMediaInstance = $this->createHelperMediaInstance();
        $mediaLocation = $helperMediaInstance->getMediaLocationByMedia($workingPath, $swMediaEntity);

        $this->assertInstanceOf(MediaLocationEntity::class, $mediaLocation);
        $this->assertIsString($mediaLocation->getMediaId());
        $this->assertIsString($mediaLocation->getImageHash());
        $this->assertEquals(
            $mediaLocation->getAbsolutePathWebp(),
            $workingPath.'/'.TestCaseBaseDefaults::MEDIA_TEST_FILE_1['path'].'.'.$mediaLocation->getImageHash().'.webp'
        );
    }

    private function createSWMediaEntity(): MediaEntity
    {
        $mediaEntity = new MediaEntity();
        $mediaEntity->setId(Uuid::randomHex());
        $mediaEntity->setCreatedAt(new \DateTimeImmutable());
        $mediaEntity->setPath(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['path']);
        $mediaEntity->setUrl(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['url']);
        $mediaEntity->setFileName(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['fileName']);
        $mediaEntity->setMimeType(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['mimeType']);
        $mediaEntity->setFileExtension(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['fileExtension']);
        $mediaEntity->setAlt(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['alt']);
        $mediaEntity->setTitle(TestCaseBaseDefaults::MEDIA_TEST_FILE_1['title']);

        return $mediaEntity;
    }

    private function createHelperMediaInstance(): HelperMedia
    {
        $HelperMedia = new HelperMedia();

        return $HelperMedia;
    }
}
