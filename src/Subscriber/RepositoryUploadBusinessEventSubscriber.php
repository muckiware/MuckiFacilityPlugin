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
namespace MuckiFacilityPlugin\Subscriber;

use MuckiFacilityPlugin\Events\DatabaseDumpFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadSucceededEvent;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the repository upload events and the database dump failure selectable as triggers in the Flow Builder.
 */
class RepositoryUploadBusinessEventSubscriber implements EventSubscriberInterface
{
    public function __construct(protected BusinessEventCollector $collector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BusinessEventCollectorEvent::NAME => 'onCollect',
        ];
    }

    public function onCollect(BusinessEventCollectorEvent $event): void
    {
        $collection = $event->getCollection();

        foreach ([RepositoryUploadSucceededEvent::class, RepositoryUploadFailedEvent::class, DatabaseDumpFailedEvent::class] as $class) {
            $definition = $this->collector->define($class);
            if ($definition === null) {
                continue;
            }
            $collection->set($definition->getName(), $definition);
        }
    }
}
