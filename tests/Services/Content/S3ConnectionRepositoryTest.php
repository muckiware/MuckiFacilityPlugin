<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionCollection;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\InvalidS3ConnectionException;
use MuckiFacilityPlugin\Exception\UnresolvableS3SecretException;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Content\S3SecretResolver;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

class S3ConnectionRepositoryTest extends TestCase
{
    private const SECRET = 'S3CR3T-value';

    private S3SecretResolver $resolver;

    private S3ConnectionRepository $repository;

    /** @var array<int, array<string, mixed>> */
    private array $created = [];

    /** @var array<int, array<string, mixed>> */
    private array $updated = [];

    private ?S3ConnectionEntity $stored = null;

    protected function setUp(): void
    {
        $this->created = [];
        $this->updated = [];
        $this->stored = null;

        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->method('create')->willReturnCallback(function (array $data): EntityWrittenContainerEvent {
            $this->created[] = $data[0];
            return $this->createStub(EntityWrittenContainerEvent::class);
        });
        $entityRepository->method('update')->willReturnCallback(function (array $data): EntityWrittenContainerEvent {
            $this->updated[] = $data[0];
            return $this->createStub(EntityWrittenContainerEvent::class);
        });
        $entityRepository->method('search')->willReturnCallback(
            fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'muwa_s3_connection',
                $this->stored === null ? 0 : 1,
                new S3ConnectionCollection($this->stored === null ? [] : [$this->stored]),
                null,
                $criteria,
                $context
            )
        );

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(sys_get_temp_dir());
        $this->resolver = new S3SecretResolver(new SecretCipher('0123456789abcdef0123456789abcdef'), $kernel);
        $this->repository = new S3ConnectionRepository($entityRepository, $this->resolver);
    }

    public function testSaveCreatesWithEncryptedSecret(): void
    {
        $id = $this->repository->save($this->input(), Context::createDefaultContext());

        self::assertTrue(Uuid::isValid($id));
        self::assertCount(1, $this->created);
        self::assertSame('encrypted', $this->created[0]['secretSource']);
        self::assertStringStartsWith('v1:', $this->created[0]['secretAccessKey']);
        self::assertStringNotContainsString(self::SECRET, $this->created[0]['secretAccessKey']);
    }

    public function testSaveWritesInSystemScope(): void
    {
        $context = Context::createDefaultContext();
        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->method('search')->willReturn(new EntitySearchResult(
            'muwa_s3_connection', 0, new S3ConnectionCollection([]), null, new Criteria(), $context
        ));
        $entityRepository->expects(self::once())->method('create')->with(
            self::anything(),
            self::callback(static fn (Context $c): bool => $c->getScope() === Context::SYSTEM_SCOPE)
        )->willReturn($this->createStub(EntityWrittenContainerEvent::class));

        (new S3ConnectionRepository($entityRepository, $this->resolver))->save($this->input(), $context);
    }

    public function testEmptySecretKeepsStoredSecret(): void
    {
        $this->stored = $this->storedConnection();

        $this->repository->save($this->input(['id' => $this->stored->getId(), 'secretAccessKey' => '', 'name' => 'Renamed']), Context::createDefaultContext());

        self::assertCount(1, $this->updated);
        self::assertSame('Renamed', $this->updated[0]['name']);
        self::assertArrayNotHasKey('secretAccessKey', $this->updated[0]);
        self::assertArrayNotHasKey('secretSource', $this->updated[0]);
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('targetChangeProvider')]
    public function testTargetChangeWithoutSecretIsRejected(array $change): void
    {
        $this->stored = $this->storedConnection();

        $this->expectException(InvalidS3ConnectionException::class);
        try {
            $this->repository->save(
                $this->input(['id' => $this->stored->getId(), 'secretAccessKey' => ''] + $change),
                Context::createDefaultContext()
            );
        } finally {
            self::assertSame([], $this->updated);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function targetChangeProvider(): array
    {
        return [
            'endpoint' => [['endpoint' => 'https://attacker.example']],
            'region' => [['region' => 'us-west-1']],
            'access key' => [['accessKeyId' => 'OTHER']],
            'provider' => [['provider' => 'ceph']],
            'source' => [['secretSource' => 'env']],
        ];
    }

    public function testTargetChangeWithNewSecretIsAccepted(): void
    {
        $this->stored = $this->storedConnection();

        $this->repository->save(
            $this->input(['id' => $this->stored->getId(), 'endpoint' => 'https://new.example']),
            Context::createDefaultContext()
        );

        self::assertCount(1, $this->updated);
    }

    public function testBuildTransientTargetChangeWithoutSecretIsRejected(): void
    {
        $this->stored = $this->storedConnection();

        $this->expectException(UnresolvableS3SecretException::class);
        $this->repository->buildTransient(['endpoint' => 'https://attacker.example', 'secretAccessKey' => ''], $this->stored);
    }

    public function testBuildTransientFallsBackToStoredValues(): void
    {
        $this->stored = $this->storedConnection();

        $entity = $this->repository->buildTransient(['id' => $this->stored->getId()], $this->stored);

        self::assertSame($this->stored->getSecretAccessKey(), $entity->getSecretAccessKey());
        self::assertSame(self::SECRET, $this->repository->resolveSecret($entity));
        self::assertSame('https://s3.example', $entity->getEndpoint());
    }

    public function testAwsRequiresRegion(): void
    {
        $this->expectException(InvalidS3ConnectionException::class);
        $this->repository->save($this->input(['provider' => 'aws', 'endpoint' => '', 'region' => '']), Context::createDefaultContext());
    }

    public function testAwsAcceptsRegionWithoutEndpoint(): void
    {
        $this->repository->save($this->input(['provider' => 'aws', 'endpoint' => '', 'region' => 'eu-central-1']), Context::createDefaultContext());

        self::assertNull($this->created[0]['endpoint']);
        self::assertSame('eu-central-1', $this->created[0]['region']);
    }

    public function testOtherRequiresEndpoint(): void
    {
        $this->expectException(InvalidS3ConnectionException::class);
        $this->repository->save($this->input(['provider' => 'other', 'endpoint' => '']), Context::createDefaultContext());
    }

    public function testHttpEndpointOnlyForMinioAndOther(): void
    {
        foreach (['aws', 'ceph'] as $provider) {
            try {
                $this->repository->save(
                    $this->input(['provider' => $provider, 'endpoint' => 'http://s3.example', 'region' => 'eu-central-1']),
                    Context::createDefaultContext()
                );
                self::fail('http must be rejected for ' . $provider);
            } catch (InvalidS3ConnectionException) {
                self::assertSame([], $this->created);
            }
        }

        foreach (['minio', 'other'] as $provider) {
            $this->repository->save($this->input(['provider' => $provider, 'endpoint' => 'http://s3.example']), Context::createDefaultContext());
        }
        self::assertCount(2, $this->created);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputIsRejected(array $override): void
    {
        $this->expectException(InvalidS3ConnectionException::class);
        $this->repository->save($this->input($override), Context::createDefaultContext());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty name' => [['name' => ' ']],
            'empty access key' => [['accessKeyId' => '']],
            'unknown provider' => [['provider' => 'gcs']],
            'unknown source' => [['secretSource' => 'plain']],
            'no secret for new' => [['secretAccessKey' => '']],
            'invalid id' => [['id' => 'nope']],
            'ftp endpoint' => [['endpoint' => 'ftp://s3.example']],
            'no host' => [['endpoint' => 'https://']],
            'line break in name' => [['name' => "a\nb"]],
            'line break in endpoint' => [['endpoint' => "https://s3.example\nX"]],
            'nul in region' => [['region' => "eu\0"]],
            'line break in access key' => [['accessKeyId' => "A\rB"]],
            'env without prefix' => [['secretSource' => 'env', 'secretAccessKey' => 'APP_SECRET']],
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function input(array $override = []): array
    {
        return array_merge([
            'name' => 'Mirror target',
            'provider' => 'minio',
            'endpoint' => 'https://s3.example',
            'region' => '',
            'accessKeyId' => 'AKIA',
            'secretSource' => 'encrypted',
            'secretAccessKey' => self::SECRET,
        ], $override);
    }

    private function storedConnection(): S3ConnectionEntity
    {
        $entity = new S3ConnectionEntity();
        $entity->setId(Uuid::randomHex());
        $entity->internalSetEntityData('muwa_s3_connection', new \Shopware\Core\Framework\DataAbstractionLayer\FieldVisibility([]));
        $entity->setName('Mirror target');
        $entity->setProvider('minio');
        $entity->setEndpoint('https://s3.example');
        $entity->setRegion(null);
        $entity->setAccessKeyId('AKIA');
        $entity->setSecretSource('encrypted');
        $entity->setSecretAccessKey($this->resolver->prepareForStorage(self::SECRET, PasswordSource::ENCRYPTED));

        return $entity;
    }
}
