<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Backup\Database\DatabaseDumperInterface;
use MuckiFacilityPlugin\Controller\DatabaseConnectionController;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionCollection;
use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Entity\DatabaseBackupTarget;
use MuckiFacilityPlugin\Entity\ResolvedDatabaseConnection;
use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Services\Content\DatabaseConnectionRepository;
use MuckiFacilityPlugin\Services\Content\DatabasePasswordResolver;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;
use MuckiFacilityPlugin\Services\DatabaseConnectionResolver;

class DatabaseConnectionControllerTest extends TestCase
{
    private const TEST_SECRET = '0123456789abcdef0123456789abcdef';

    private const SECRET_PASSWORD = 'sup3r-s3cret-pw';

    /** @var EntityRepository<DatabaseConnectionCollection>&MockObject */
    private EntityRepository $entityRepository;

    private DatabasePasswordResolver $passwordResolver;

    /** @var DatabaseConnectionResolver&MockObject */
    private DatabaseConnectionResolver $connectionResolver;

    private DatabaseConnectionController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $created = [];

    /** @var array<int, array<string, mixed>> */
    private array $updated = [];

    private ?DatabaseConnectionEntity $storedEntity = null;

    protected function setUp(): void
    {
        $this->created = [];
        $this->updated = [];
        $this->storedEntity = null;

        $this->entityRepository = $this->createMock(EntityRepository::class);
        $this->entityRepository->method('create')->willReturnCallback(
            function (array $data): \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent {
                $this->created[] = $data[0];
                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
            }
        );
        $this->entityRepository->method('update')->willReturnCallback(
            function (array $data): \Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent {
                $this->updated[] = $data[0];
                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
            }
        );
        $this->entityRepository->method('search')->willReturnCallback(
            fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'muwa_database_connection',
                $this->storedEntity === null ? 0 : 1,
                new DatabaseConnectionCollection($this->storedEntity === null ? [] : [$this->storedEntity]),
                null,
                $criteria,
                $context
            )
        );

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(sys_get_temp_dir());
        $this->passwordResolver = new DatabasePasswordResolver(new SecretCipher(self::TEST_SECRET), $kernel);

        $this->connectionResolver = $this->createMock(DatabaseConnectionResolver::class);

        $this->controller = new DatabaseConnectionController(
            new DatabaseConnectionRepository($this->entityRepository, $this->passwordResolver),
            $this->connectionResolver
        );
    }

    public function testSaveCreatesConnectionWithEncryptedPassword(): void
    {
        $id = Uuid::randomHex();
        $response = $this->controller->save(new RequestDataBag($this->input(['id' => $id])), $this->context(true));

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(['success' => true, 'id' => $id], $this->decode($response));
        static::assertCount(1, $this->created);
        static::assertSame($id, $this->created[0]['id']);
        static::assertSame('encrypted', $this->created[0]['passwordSource']);
        static::assertStringStartsWith('v1:', $this->created[0]['password']);
        static::assertStringNotContainsString(self::SECRET_PASSWORD, $this->created[0]['password']);
        static::assertSame(self::SECRET_PASSWORD, $this->passwordResolver->resolve(
            $this->created[0]['password'],
            \MuckiFacilityPlugin\Core\PasswordSource::ENCRYPTED
        ));
    }

    public function testSaveGeneratesIdForNewConnectionAndNormalisesType(): void
    {
        $response = $this->controller->save(
            new RequestDataBag($this->input(['type' => '  MySQL ', 'port' => ''])),
            $this->context(true)
        );

        $body = $this->decode($response);
        static::assertTrue(Uuid::isValid($body['id']));
        static::assertSame('mysql', $this->created[0]['type']);
        static::assertNull($this->created[0]['port']);
    }

    public function testSaveWithEmptyPasswordKeepsStoredPassword(): void
    {
        $this->storedEntity = $this->storedConnection();

        $response = $this->controller->save(
            new RequestDataBag($this->input(['id' => $this->storedEntity->getId(), 'password' => '', 'name' => 'Renamed'])),
            $this->context(false)
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertSame([], $this->created);
        static::assertCount(1, $this->updated);
        static::assertSame('Renamed', $this->updated[0]['name']);
        static::assertArrayNotHasKey('password', $this->updated[0]);
        static::assertArrayNotHasKey('passwordSource', $this->updated[0]);
    }

    public function testSaveWithEmptyPasswordAndChangedSourceIsRejected(): void
    {
        $this->storedEntity = $this->storedConnection();

        $response = $this->controller->save(
            new RequestDataBag($this->input([
                'id' => $this->storedEntity->getId(),
                'password' => '',
                'passwordSource' => 'env',
            ])),
            $this->context(false)
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertSame([], $this->updated);
    }

    public function testSaveRejectsEnvWithoutPrefix(): void
    {
        $response = $this->controller->save(
            new RequestDataBag($this->input(['passwordSource' => 'env', 'password' => 'APP_SECRET'])),
            $this->context(true)
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertSame('MUWA_INVALID_DATABASE_CONNECTION', $this->decode($response)['errors'][0]['code']);
        static::assertSame([], $this->created);
    }

    public function testSaveRejectsPasswordWithLineBreak(): void
    {
        $response = $this->controller->save(
            new RequestDataBag($this->input(['password' => "pw\nuser=root"])),
            $this->context(true)
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertStringNotContainsString('user=root', (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $override
     * @dataProvider invalidInputProvider
     */
    public function testSaveRejectsInvalidInput(array $override): void
    {
        $response = $this->controller->save(new RequestDataBag($this->input($override)), $this->context(true));

        static::assertSame(400, $response->getStatusCode());
        static::assertSame('MUWA_INVALID_DATABASE_CONNECTION', $this->decode($response)['errors'][0]['code']);
        static::assertSame([], $this->created);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty name' => [['name' => ' ']],
            'empty host' => [['host' => '']],
            'empty database' => [['databaseName' => '']],
            'empty username' => [['username' => '']],
            'empty type' => [['type' => ' ']],
            'long type' => [['type' => str_repeat('a', 33)]],
            'port zero' => [['port' => '0']],
            'port too high' => [['port' => '65536']],
            'port not numeric' => [['port' => 'abc']],
            'unknown source' => [['passwordSource' => 'plain']],
            'new without password' => [['password' => '']],
            'invalid id' => [['id' => 'not-a-uuid']],
            'line break in host' => [['host' => "db.example\nuser=root"]],
            'carriage return in username' => [['username' => "backup\rhost=evil"]],
            'nul in database name' => [['databaseName' => "shop\0x"]],
            'line break in name' => [['name' => "Shop\nDB"]],
        ];
    }

    public function testTestRouteRejectsLineBreakInTargetFields(): void
    {
        $this->connectionResolver->expects(static::never())->method('resolveConnection');

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['host' => "db.example\nuser=root"])),
            $this->context(true)
        );

        static::assertSame(400, $response->getStatusCode());
        static::assertSame('MUWA_INVALID_DATABASE_CONNECTION', $this->decode($response)['errors'][0]['code']);
    }

    public function testSaveNewConnectionRequiresCreatePrivilege(): void
    {
        $response = $this->controller->save(new RequestDataBag($this->input()), $this->context(false));

        static::assertSame(403, $response->getStatusCode());
        static::assertSame([], $this->created);
    }

    public function testSaveExistingConnectionDoesNotRequireCreatePrivilege(): void
    {
        $this->storedEntity = $this->storedConnection();

        $response = $this->controller->save(
            new RequestDataBag($this->input(['id' => $this->storedEntity->getId()])),
            $this->context(false)
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertCount(1, $this->updated);
    }

    public function testTestRouteWithUnsavedValuesDispatchesResolver(): void
    {
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->expects(static::once())->method('testConnection')
            ->with(static::callback(
                static fn (ResolvedDatabaseConnection $c): bool => $c->getPassword() === self::SECRET_PASSWORD
                    && $c->getHost() === 'db.example'
            ));
        $target = new DatabaseBackupTarget($this->resolved(), $dumper, false, 'Shop');

        $this->connectionResolver->expects(static::once())->method('resolveConnection')
            ->with(static::callback(function (DatabaseConnectionEntity $entity): bool {
                return $entity->getHost() === 'db.example'
                    && $entity->getType() === 'mysql'
                    && $this->passwordResolver->resolve(
                        $entity->getPassword(),
                        \MuckiFacilityPlugin\Core\PasswordSource::from($entity->getPasswordSource())
                    ) === self::SECRET_PASSWORD;
            }))
            ->willReturn($target);

        $response = $this->controller->testConnection(new RequestDataBag($this->input()), $this->context(false));

        static::assertSame(200, $response->getStatusCode());
        static::assertTrue($this->decode($response)['success']);
    }

    public function testTestRouteWithOnlyIdUsesStoredConnection(): void
    {
        $this->storedEntity = $this->storedConnection();
        $dumper = $this->createMock(DatabaseDumperInterface::class);
        $dumper->expects(static::once())->method('testConnection');
        $target = new DatabaseBackupTarget($this->resolved(), $dumper, false, 'Shop');

        $this->connectionResolver->expects(static::once())->method('resolveConnection')
            ->with(static::callback(
                fn (DatabaseConnectionEntity $entity): bool => $entity->getId() === $this->storedEntity?->getId()
                    && $entity->getPassword() === $this->storedEntity?->getPassword()
                    && $entity->getHost() === 'db.example'
            ))
            ->willReturn($target);

        $response = $this->controller->testConnection(
            new RequestDataBag(['id' => $this->storedEntity->getId()]),
            $this->context(false)
        );

        static::assertTrue($this->decode($response)['success']);
    }

    public function testTestRouteWithEmptyPasswordUsesStoredPassword(): void
    {
        $this->storedEntity = $this->storedConnection();
        $this->connectionResolver->expects(static::once())->method('resolveConnection')
            ->with(static::callback(
                fn (DatabaseConnectionEntity $entity): bool => $entity->getPassword() === $this->storedEntity?->getPassword()
                    && $entity->getName() === 'Renamed'
            ))
            ->willReturn(new DatabaseBackupTarget(
                $this->resolved(),
                $this->createStub(DatabaseDumperInterface::class),
                false,
                'Shop'
            ));

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input([
                'id' => $this->storedEntity->getId(),
                'name' => 'Renamed',
                'password' => '',
            ])),
            $this->context(false)
        );

        static::assertTrue($this->decode($response)['success']);
    }

    public function testSaveWithChangedHostAndEmptyPasswordIsRejected(): void
    {
        foreach ([['host' => 'attacker.example'], ['port' => '3307'], ['username' => 'root'], ['type' => 'pgsql']] as $change) {
            $this->storedEntity = $this->storedConnection();
            $response = $this->controller->save(
                new RequestDataBag($this->input(['id' => $this->storedEntity->getId(), 'password' => ''] + $change)),
                $this->context(false)
            );

            static::assertSame(400, $response->getStatusCode());
            static::assertSame('MUWA_INVALID_DATABASE_CONNECTION', $this->decode($response)['errors'][0]['code']);
        }
        static::assertSame([], $this->updated);
    }

    public function testSaveWithChangedHostAndNewPasswordIsAccepted(): void
    {
        $this->storedEntity = $this->storedConnection();
        $response = $this->controller->save(
            new RequestDataBag($this->input(['id' => $this->storedEntity->getId(), 'host' => 'new.example'])),
            $this->context(false)
        );

        static::assertSame(200, $response->getStatusCode());
        static::assertCount(1, $this->updated);
    }

    public function testTestRouteWithChangedHostAndEmptyPasswordIsRejected(): void
    {
        $this->storedEntity = $this->storedConnection();
        $this->connectionResolver->expects(static::never())->method('resolveConnection');

        foreach ([['host' => 'attacker.example'], ['port' => '3307'], ['username' => 'root'], ['type' => 'pgsql']] as $change) {
            $response = $this->controller->testConnection(
                new RequestDataBag(['id' => $this->storedEntity->getId(), 'password' => ''] + $change),
                $this->context(false)
            );

            static::assertSame(200, $response->getStatusCode());
            static::assertFalse($this->decode($response)['success']);
        }
    }

    public function testTestRouteRejectsMalformedId(): void
    {
        $this->connectionResolver->expects(static::never())->method('resolveConnection');

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['id' => 'not-a-uuid'])),
            $this->context(false)
        );

        static::assertSame(400, $response->getStatusCode());
    }

    public function testTestRouteReturnsMaskedErrorMessage(): void
    {
        $dumper = $this->createStub(DatabaseDumperInterface::class);
        $dumper->method('testConnection')->willThrowException(
            new DatabaseBackupFailedException('Access denied for user (password: ***)')
        );
        $this->connectionResolver->method('resolveConnection')->willReturn(
            new DatabaseBackupTarget($this->resolved(), $dumper, false, 'Shop')
        );

        $response = $this->controller->testConnection(new RequestDataBag($this->input()), $this->context(false));

        static::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        static::assertFalse($body['success']);
        static::assertSame('Access denied for user (password: ***)', $body['message']);
        static::assertStringNotContainsString(self::SECRET_PASSWORD, (string) $response->getContent());
    }

    public function testTestRouteReportsUnresolvablePasswordAsFailure(): void
    {
        $this->connectionResolver->expects(static::never())->method('resolveConnection');

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['passwordSource' => 'env', 'password' => 'APP_SECRET'])),
            $this->context(false)
        );

        static::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        static::assertFalse($body['success']);
        static::assertNotSame('', $body['message']);
    }

    public function testTestRouteWithoutPasswordAndWithoutStoredConnectionFails(): void
    {
        $this->connectionResolver->expects(static::never())->method('resolveConnection');

        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['password' => ''])),
            $this->context(false)
        );

        static::assertFalse($this->decode($response)['success']);
    }

    public function testTestRouteRejectsInvalidFormValues(): void
    {
        $response = $this->controller->testConnection(
            new RequestDataBag($this->input(['port' => '99999'])),
            $this->context(false)
        );

        static::assertSame(400, $response->getStatusCode());
    }

    public function testResponsesNeverContainPassword(): void
    {
        $saveResponse = $this->controller->save(new RequestDataBag($this->input()), $this->context(true));
        static::assertStringNotContainsString(self::SECRET_PASSWORD, (string) $saveResponse->getContent());

        $dumper = $this->createStub(DatabaseDumperInterface::class);
        $this->connectionResolver->method('resolveConnection')->willReturn(
            new DatabaseBackupTarget($this->resolved(), $dumper, false, 'Shop')
        );
        $testResponse = $this->controller->testConnection(new RequestDataBag($this->input()), $this->context(false));
        static::assertStringNotContainsString(self::SECRET_PASSWORD, (string) $testResponse->getContent());
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function input(array $override = []): array
    {
        return array_merge([
            'name' => 'Shop DB',
            'type' => 'mysql',
            'host' => 'db.example',
            'port' => '3306',
            'databaseName' => 'shop',
            'username' => 'backup',
            'passwordSource' => 'encrypted',
            'password' => self::SECRET_PASSWORD,
        ], $override);
    }

    private function storedConnection(): DatabaseConnectionEntity
    {
        $entity = new DatabaseConnectionEntity();
        $entity->setId(Uuid::randomHex());
        $entity->internalSetEntityData('muwa_database_connection', new \Shopware\Core\Framework\DataAbstractionLayer\FieldVisibility([]));
        $entity->setName('Shop DB');
        $entity->setType('mysql');
        $entity->setHost('db.example');
        $entity->setPort(3306);
        $entity->setDatabaseName('shop');
        $entity->setUsername('backup');
        $entity->setPasswordSource('encrypted');
        $entity->setPassword($this->passwordResolver->prepareForStorage(
            self::SECRET_PASSWORD,
            \MuckiFacilityPlugin\Core\PasswordSource::ENCRYPTED
        ));

        return $entity;
    }

    private function resolved(): ResolvedDatabaseConnection
    {
        return new ResolvedDatabaseConnection('mysql', 'db.example', 3306, 'shop', 'backup', self::SECRET_PASSWORD);
    }

    private function context(bool $allowCreate): Context
    {
        $source = new AdminApiSource(Uuid::randomHex());
        $source->setPermissions(array_merge(
            ['muwa_database_connection:update'],
            $allowCreate ? ['muwa_database_connection:create'] : []
        ));

        return new Context($source);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
