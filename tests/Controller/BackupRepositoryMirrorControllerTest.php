<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use MuckiFacilityPlugin\Controller\BackupRepositoryMirrorController;
use MuckiFacilityPlugin\Exception\InvalidMirrorTargetException;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;

/**
 * The mirror route is the only write path of the (WriteProtected) mirror target fields.
 */
final class BackupRepositoryMirrorControllerTest extends TestCase
{
    private const CONN = '11111111111111111111111111111111';

    private function errorCode(\Symfony\Component\HttpFoundation\Response $response): string
    {
        self::assertSame(400, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true)['errors'][0]['code'];
    }

    public function testSavesValidatedTarget(): void
    {
        $id = Uuid::randomHex();
        $payload = ['mirrorS3ConnectionId' => self::CONN, 'mirrorS3Bucket' => 'bkt', 'mirrorS3Prefix' => 'a/'];

        $validator = $this->createMock(MirrorTargetValidator::class);
        $validator->method('assertValid')->willReturn($payload);
        $validator->expects(self::once())->method('findOverlap')->with($id, self::CONN, 'bkt', 'a/')->willReturn(null);
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(true);
        $service->expects(self::once())->method('updateMirrorTarget')->with($id, $payload)->willReturn(true);

        $response = (new BackupRepositoryMirrorController($service, $validator))
            ->update($id, new RequestDataBag(['s3ConnectionId' => self::CONN, 'bucket' => 'bkt', 'prefix' => 'a']), Context::createDefaultContext());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testNullConnectionClearsAllFields(): void
    {
        $id = Uuid::randomHex();
        $cleared = ['mirrorS3ConnectionId' => null, 'mirrorS3Bucket' => null, 'mirrorS3Prefix' => null];

        $validator = $this->createMock(MirrorTargetValidator::class);
        $validator->method('assertValid')->willReturn($cleared);
        $validator->expects(self::never())->method('findOverlap');
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(true);
        $service->expects(self::once())->method('updateMirrorTarget')->with($id, $cleared)->willReturn(true);

        $response = (new BackupRepositoryMirrorController($service, $validator))
            ->update($id, new RequestDataBag(['s3ConnectionId' => null]), Context::createDefaultContext());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testInvalidIdIsRejected(): void
    {
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(true);
        $service->expects(self::never())->method('updateMirrorTarget');

        $response = (new BackupRepositoryMirrorController($service, $this->createMock(MirrorTargetValidator::class)))
            ->update('nope', new RequestDataBag([]), Context::createDefaultContext());

        self::assertSame('MUWA_INVALID_ID', $this->errorCode($response));
    }

    public function testInvalidTargetIsRejected(): void
    {
        $validator = $this->createMock(MirrorTargetValidator::class);
        $validator->method('assertValid')->willThrowException(new InvalidMirrorTargetException('bad'));
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(true);
        $service->expects(self::never())->method('updateMirrorTarget');

        $response = (new BackupRepositoryMirrorController($service, $validator))
            ->update(Uuid::randomHex(), new RequestDataBag([]), Context::createDefaultContext());

        self::assertSame('MUWA_INVALID_MIRROR_TARGET', $this->errorCode($response));
    }

    public function testOverlapIsRejectedWithConflictingName(): void
    {
        $validator = $this->createMock(MirrorTargetValidator::class);
        $validator->method('assertValid')->willReturn(
            ['mirrorS3ConnectionId' => self::CONN, 'mirrorS3Bucket' => 'bkt', 'mirrorS3Prefix' => '']
        );
        $validator->method('findOverlap')->willReturn('Shop repo');
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(true);
        $service->expects(self::never())->method('updateMirrorTarget');

        $response = (new BackupRepositoryMirrorController($service, $validator))
            ->update(Uuid::randomHex(), new RequestDataBag([]), Context::createDefaultContext());

        self::assertSame('MUWA_MIRROR_TARGET_OVERLAP', $this->errorCode($response));
        self::assertStringContainsString('Shop repo', (string) $response->getContent());
    }

    public function testUnknownRepositoryYields404BeforeValidation(): void
    {
        $validator = $this->createMock(MirrorTargetValidator::class);
        $validator->expects(self::never())->method('assertValid');
        $validator->expects(self::never())->method('findOverlap');
        $service = $this->createMock(BackupRepositoryService::class);
        $service->method('exists')->willReturn(false);
        $service->expects(self::never())->method('updateMirrorTarget');

        $this->expectException(NotFoundHttpException::class);
        (new BackupRepositoryMirrorController($service, $validator))
            ->update(Uuid::randomHex(), new RequestDataBag([]), Context::createDefaultContext());
    }
}
