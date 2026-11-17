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

use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Events\RepositoryUploadFailedEvent;
use MuckiFacilityPlugin\Events\RepositoryUploadSucceededEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;

class RepositoryUploadEventTest extends TestCase
{
    private function mirrorResult(): MirrorResult
    {
        return new MirrorResult(
            status: MirrorResult::STATUS_SUCCEEDED,
            triggerSource: 'schedule',
            bucket: 'my-bucket',
            prefix: 'repo/',
            s3ConnectionName: 'Main S3',
            startedAt: new \DateTimeImmutable('2026-11-16T10:00:00+00:00'),
            finishedAt: new \DateTimeImmutable('2026-11-16T10:05:00+00:00'),
            filesUploaded: 3,
            filesDeleted: 1,
            filesUnchanged: 7,
            bytesUploaded: 2048,
            message: null
        );
    }

    public function testValuesContainNoSecretKeys(): void
    {
        $event = new RepositoryUploadSucceededEvent('id-1', 'Repo', $this->mirrorResult(), Context::createDefaultContext());

        static::assertSame([
            'backupRepositoryId', 'backupRepositoryName', 'status', 'bucket', 'prefix', 's3ConnectionName',
            'startedAt', 'finishedAt', 'filesUploaded', 'filesDeleted', 'bytesUploaded', 'message',
        ], array_keys($event->getValues()));
        static::assertSame('succeeded', $event->getValues()['status']);
        static::assertSame('2026-11-16T10:00:00+00:00', $event->getValues()['startedAt']);
        static::assertSame(2048, $event->getValues()['bytesUploaded']);
    }

    public function testInterfacesAndMailStruct(): void
    {
        $event = new RepositoryUploadFailedEvent('id-1', 'Repo', $this->mirrorResult(), Context::createDefaultContext());

        static::assertInstanceOf(FlowEventAware::class, $event);
        static::assertInstanceOf(MailAware::class, $event);
        static::assertInstanceOf(ScalarValuesAware::class, $event);
        static::assertSame([], $event->getMailStruct()->getRecipients());
        static::assertNull($event->getSalesChannelId());
        static::assertSame(Context::createDefaultContext()->getSource()::class, $event->getContext()->getSource()::class);
    }

    public function testNameWorksWithoutConstructor(): void
    {
        $failed = (new \ReflectionClass(RepositoryUploadFailedEvent::class))->newInstanceWithoutConstructor();
        $ok = (new \ReflectionClass(RepositoryUploadSucceededEvent::class))->newInstanceWithoutConstructor();

        static::assertSame('mucki_facility.repository_upload.failed', $failed->getName());
        static::assertSame('mucki_facility.repository_upload.succeeded', $ok->getName());
    }

    public function testAvailableDataMatchesValues(): void
    {
        $event = new RepositoryUploadSucceededEvent('id-1', 'Repo', $this->mirrorResult(), Context::createDefaultContext());

        $data = RepositoryUploadSucceededEvent::getAvailableData()->toArray();
        static::assertSame(array_keys($event->getValues()), array_keys($data));
        static::assertSame('int', $data['filesUploaded']['type']);
        static::assertSame('string', $data['status']['type']);
    }
}
