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
namespace MuckiFacilityPlugin\tests\Events;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Events\DatabaseDumpFailedEvent;

class DatabaseDumpFailedEventTest extends TestCase
{
    public function testValues(): void
    {
        $settings = new BackupRepositorySettings();
        $settings->setBackupRepositoryId('repo-id');
        $settings->setName('Shop');

        $event = new DatabaseDumpFailedEvent($settings, ['Dump failed:a', 'Dump failed:b'], true, Context::createDefaultContext());

        static::assertSame([
            'backupRepositoryId' => 'repo-id',
            'backupRepositoryName' => 'Shop',
            'failedCount' => 2,
            'backupAborted' => true,
            'message' => 'Dump failed:a' . PHP_EOL . 'Dump failed:b',
        ], $event->getValues());
        static::assertInstanceOf(FlowEventAware::class, $event);
        static::assertInstanceOf(MailAware::class, $event);
        static::assertInstanceOf(ScalarValuesAware::class, $event);
        static::assertNull($event->getSalesChannelId());
    }

    /**
     * muckiware:db:dump starts a dump without repository: id and name are not initialized.
     */
    public function testValuesWithoutRepository(): void
    {
        $event = new DatabaseDumpFailedEvent(new BackupRepositorySettings(), ['x'], false, Context::createDefaultContext());

        static::assertSame('', $event->getValues()['backupRepositoryId']);
        static::assertSame('', $event->getValues()['backupRepositoryName']);
    }

    public function testNameWorksWithoutConstructor(): void
    {
        $event = (new \ReflectionClass(DatabaseDumpFailedEvent::class))->newInstanceWithoutConstructor();

        static::assertSame('mucki_facility.database_dump.failed', $event->getName());
    }

    public function testAvailableDataMatchesValues(): void
    {
        $event = new DatabaseDumpFailedEvent(new BackupRepositorySettings(), ['x'], false, Context::createDefaultContext());

        static::assertSame(
            array_keys($event->getValues()),
            array_keys(DatabaseDumpFailedEvent::getAvailableData()->toArray())
        );
    }
}
