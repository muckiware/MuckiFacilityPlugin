<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use MuckiFacilityPlugin\Controller\BatchFileController;
use MuckiFacilityPlugin\Services\BatchFileUploader;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class BatchFileControllerTest extends TestCase
{
    public function testUploadReturnsStoredAbsolutePath(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $uploader = $this->createMock(BatchFileUploader::class);
        $uploader->expects(self::once())
            ->method('upload')
            ->with($file)
            ->willReturn('/shop/files/private/mucki-facility/batch/nightly.sh');

        $request = new Request([], [], [], [], ['file' => $file]);
        $response = (new BatchFileController($uploader))->upload($request, Context::createDefaultContext());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['path' => '/shop/files/private/mucki-facility/batch/nightly.sh'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testUploadRejectsMissingFile(): void
    {
        $uploader = $this->createMock(BatchFileUploader::class);
        $uploader->expects(self::never())->method('upload');

        $response = (new BatchFileController($uploader))->upload(
            new Request(),
            Context::createDefaultContext()
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'No batch file was uploaded',
            json_decode((string) $response->getContent(), true)['errors'][0]['detail']
        );
    }

    public function testUploadReturnsBadRequestForInvalidFile(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $uploader = $this->createMock(BatchFileUploader::class);
        $uploader->method('upload')->willThrowException(new \InvalidArgumentException('Only .sh files can be uploaded'));

        $request = new Request([], [], [], [], ['file' => $file]);
        $response = (new BatchFileController($uploader))->upload($request, Context::createDefaultContext());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'Only .sh files can be uploaded',
            json_decode((string) $response->getContent(), true)['errors'][0]['detail']
        );
    }
}
