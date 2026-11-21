<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Events;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiRestic\Entity\Result\ResultEntity;
use PHPUnit\Framework\TestCase;

final class BackupFinishedEventTest extends TestCase
{
    public function testExposesUnchangedBackupOutcome(): void
    {
        $settings = new BackupRepositorySettings();
        $firstResult = new ResultEntity();
        $firstResult->setOutput('first');
        $secondResult = new ResultEntity();
        $secondResult->setOutput('second');
        $results = [$firstResult, $secondResult];
        $primaryError = new \RuntimeException('primary failure');

        $event = new BackupFinishedEvent(
            $settings,
            $results,
            $primaryError,
            false
        );

        static::assertSame('mucki_facility.backup.finished', BackupFinishedEvent::NAME);
        static::assertSame($settings, $event->getBackupSettings());
        static::assertSame($results, $event->getResults());
        static::assertSame($primaryError, $event->getPrimaryError());
        static::assertFalse($event->isCoreSucceeded());
    }
}
