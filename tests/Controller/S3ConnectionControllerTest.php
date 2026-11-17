<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Controller\S3ConnectionController;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionCollection;
use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Content\S3SecretResolver;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;
use MuckiFacilityPlugin\Services\Mirror\S3ClientFactory;

class S3ConnectionControllerTest extends TestCase
{
    private const SECRET = 'S3CR3T-value';

    private S3SecretResolver $resolver;

    private MockHandler $mockHandler;

    private S3ConnectionController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $created = [];

    /** @var array<int, array<string, mixed>> */
    private array $updated = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $httpOptions = [];

    private ?S3ConnectionEntity $stored = null;

    protected function setUp(): void
    {
        $this->created = [];
        $this->updated = [];
        $this->stored = null;
        $this->mockHandler = new MockHandler();

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

        $handler = $this->mockHandler;
        $this->httpOptions = [];
        $factory = new class ($handler, $this->httpOptions) extends S3ClientFactory {
            /** @param list<array<string, mixed>> $httpOptions */
            public function __construct(private readonly MockHandler $mock, private array &$httpOptions)
            {
            }

            public function create(
                S3ConnectionEntity $connection,
                string $secret,
                ?callable $handler = null,
                array $httpOptions = []
            ): S3Client {
                $this->httpOptions[] = $httpOptions;

                return parent::create($connection, $secret, $this->mock, $httpOptions);
            }
        };

        $this->controller = new S3ConnectionController(
            new S3ConnectionRepository($entityRepository, $this->resolver),
            $factory
        );
    }

    public function testCreateRequiresCreatePrivilege(): void
    {
        $response = $this->controller->save(new RequestDataBag($this->input()), $this->context(false));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->created);
    }

    public function testCreateWithPrivilegeReturnsId(): void
    {
        $response = $this->controller->save(new RequestDataBag($this->input()), $this->context(true));

        $body = $this->decode($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertCount(1, $this->created);
        self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function testUpdateDoesNotRequireCreatePrivilege(): void
    {
        $this->stored = $this->storedConnection();

        $response = $this->controller->save(
            new RequestDataBag($this->input(['id' => $this->stored->getId()])),
            $this->context(false)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->updated);
    }

    public function testSaveInvalidInputReturns400(): void
    {
        $response = $this->controller->save(new RequestDataBag($this->input(['provider' => 'aws', 'region' => ''])), $this->context(true));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('MUWA_INVALID_S3_CONNECTION', $this->decode($response)['errors'][0]['code']);
    }

    public function testTestRouteWithBucketUsesHeadBucket(): void
    {
        $this->mockHandler->append(new Result([]));

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['bucket' => 'my-bucket'])),
            $this->context(false)
        );

        self::assertTrue($this->decode($response)['success']);
        self::assertSame('HeadBucket', $this->mockHandler->getLastCommand()->getName());
        self::assertSame('my-bucket', $this->mockHandler->getLastCommand()['Bucket']);
    }

    public function testTestRouteUsesShortTimeouts(): void
    {
        $this->mockHandler->append(new Result(['Buckets' => []]));

        $this->controller->testConnection(new RequestDataBag($this->input()), $this->context(false));

        self::assertSame([['connect_timeout' => 5, 'timeout' => 15]], $this->httpOptions);
    }

    public function testTestRouteWithoutBucketListsBuckets(): void
    {
        $this->mockHandler->append(new Result(['Buckets' => []]));

        $response = $this->controller->testConnection(new RequestDataBag($this->input()), $this->context(false));

        self::assertTrue($this->decode($response)['success']);
        self::assertSame('ListBuckets', $this->mockHandler->getLastCommand()->getName());
    }

    public function testTestRouteReturnsSanitizedFailure(): void
    {
        $this->mockHandler->append(new AwsException(
            'raw ' . self::SECRET,
            new Command('HeadBucket'),
            ['code' => 'AccessDenied', 'message' => 'Denied for ' . self::SECRET]
        ));

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['bucket' => 'my-bucket'])),
            $this->context(false)
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertFalse($body['success']);
        self::assertStringStartsWith('AccessDenied: ', $body['message']);
        self::assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function testTestRouteWithOnlyIdUsesStoredSecret(): void
    {
        $this->stored = $this->storedConnection();
        $this->mockHandler->append(new Result(['Buckets' => []]));

        $response = $this->controller->testConnection(
            new RequestDataBag(['id' => $this->stored->getId()]),
            $this->context(false)
        );

        self::assertTrue($this->decode($response)['success']);
    }

    public function testTestRouteWithChangedEndpointAndNoSecretFails(): void
    {
        $this->stored = $this->storedConnection();

        $response = $this->controller->testConnection(
            new RequestDataBag(['id' => $this->stored->getId(), 'endpoint' => 'https://attacker.example', 'secretAccessKey' => '']),
            $this->context(false)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
        self::assertNull($this->mockHandler->getLastCommand());
    }

    public function testTestRouteInvalidBucketNameReturns400(): void
    {
        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['bucket' => 'My_Bucket'])),
            $this->context(false)
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('MUWA_INVALID_S3_CONNECTION', $this->decode($response)['errors'][0]['code']);
        self::assertNull($this->mockHandler->getLastCommand());
    }

    public function testTestRouteNonStringBucketReturns400(): void
    {
        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['bucket' => ['x']])),
            $this->context(false)
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('MUWA_INVALID_S3_CONNECTION', $this->decode($response)['errors'][0]['code']);
    }

    public function testNonScalarBodyValuesReturn400WithoutWarning(): void
    {
        $warnings = [];
        // only warnings/notices: Shopware deprecation notices of the mocked search results are not relevant here
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            foreach (['id', 'name', 'endpoint', 'accessKeyId', 'secretAccessKey', 'secretSource'] as $field) {
                foreach (['save', 'testConnection'] as $action) {
                    $response = $this->controller->{$action}(
                        new RequestDataBag($this->input([$field => ['x' => 'y']])),
                        $this->context(true)
                    );
                    self::assertSame(400, $response->getStatusCode(), $action . ' ' . $field);
                    self::assertSame(
                        'MUWA_INVALID_S3_CONNECTION',
                        $this->decode($response)['errors'][0]['code'],
                        $action . ' ' . $field
                    );
                }
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertNull($this->mockHandler->getLastCommand());
    }

    public function testTestRouteInvalidFieldsReturn400(): void
    {
        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['endpoint' => "https://x\nY"])),
            $this->context(false)
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('MUWA_INVALID_S3_CONNECTION', $this->decode($response)['errors'][0]['code']);
    }

    public function testTestRouteWithoutSecretAndWithoutStoredFails(): void
    {
        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['secretAccessKey' => ''])),
            $this->context(false)
        );

        self::assertFalse($this->decode($response)['success']);
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
        $entity->setAccessKeyId('AKIA');
        $entity->setSecretSource('encrypted');
        $entity->setSecretAccessKey($this->resolver->prepareForStorage(self::SECRET, PasswordSource::ENCRYPTED));

        return $entity;
    }

    private function context(bool $allowCreate): Context
    {
        $source = new AdminApiSource(Uuid::randomHex());
        $source->setPermissions($allowCreate ? ['muwa_s3_connection:create'] : []);

        return new Context($source);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
