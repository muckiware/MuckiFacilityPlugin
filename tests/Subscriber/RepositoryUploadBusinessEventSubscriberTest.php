<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Subscriber;

use MuckiFacilityPlugin\Events\RepositoryUploadFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadSucceededEvent;
use MuckiFacilityPlugin\Subscriber\RepositoryUploadBusinessEventSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Shopware\Core\Framework\Event\BusinessEventCollectorResponse;
use Shopware\Core\Framework\Event\BusinessEventRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcher;

class RepositoryUploadBusinessEventSubscriberTest extends TestCase
{
    public function testSubscriberRegistersBothEvents(): void
    {
        $connection = $this->createMock(Connection::class);
        $collector = new BusinessEventCollector(new BusinessEventRegistry(), new EventDispatcher(), $connection);

        $response = new BusinessEventCollectorResponse();
        $event = new BusinessEventCollectorEvent($response, Context::createDefaultContext());

        $subscriber = new RepositoryUploadBusinessEventSubscriber($collector);
        static::assertArrayHasKey(BusinessEventCollectorEvent::NAME, RepositoryUploadBusinessEventSubscriber::getSubscribedEvents());
        $subscriber->onCollect($event);

        static::assertTrue($response->has(RepositoryUploadSucceededEvent::EVENT_NAME));
        static::assertTrue($response->has(RepositoryUploadFailedEvent::EVENT_NAME));
        static::assertSame(RepositoryUploadFailedEvent::class, $response->get(RepositoryUploadFailedEvent::EVENT_NAME)->getClass());
    }
}
