<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksCollection;
use MuckiFacilityPlugin\Core\Content\BackupRepository\Checks\BackupRepositoryChecksEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\SettingsInterface;

class BackupRepositoryChecksTest extends TestCase
{
    public function testSaveNewCheckReturnsIdOfWrittenEntry(): void
    {
        $written = [];
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('create')->willReturnCallback(
            static function (array $data) use (&$written): EntityWrittenContainerEvent {
                $written = $data;

                return new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);
            }
        );

        $id = $this->service($repository)->saveNewCheck(str_repeat('a', 32), 'no errors were found');

        self::assertTrue(Uuid::isValid($id));
        self::assertSame($id, $written[0]['id']);
    }

    public function testGetByIdReturnsEntityOrNull(): void
    {
        $id = str_repeat('b', 32);
        $entity = new BackupRepositoryChecksEntity();
        $entity->setId($id);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria) use ($entity, $id): EntitySearchResult {
                $found = $criteria->getIds() === [$id] ? [$entity] : [];

                return new EntitySearchResult(
                    $entity->getApiAlias(),
                    count($found),
                    new BackupRepositoryChecksCollection($found),
                    null,
                    $criteria,
                    Context::createDefaultContext()
                );
            }
        );

        $service = $this->service($repository);
        self::assertSame($entity, $service->getById($id));
        self::assertNull($service->getById(str_repeat('c', 32)));
        self::assertNull($service->getById('not-a-uuid'));
    }

    private function service(EntityRepository $repository): BackupRepositoryChecks
    {
        return new BackupRepositoryChecks(
            $this->createMock(LoggerInterface::class),
            $this->createMock(SettingsInterface::class),
            $repository
        );
    }
}
