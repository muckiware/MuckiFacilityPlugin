<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Schedule;

use PHPUnit\Framework\TestCase;

final class BackupNotificationAdministrationTest extends TestCase
{
    public function testBackupNotificationsAreTranslatedWithRuntimeParameters(): void
    {
        $administrationPath = __DIR__ . '/../../src/Resources/app/administration/src/';
        $extension = file_get_contents($administrationPath . 'extension/backup-notification-translation/index.js');
        $main = file_get_contents($administrationPath . 'main.js');

        self::assertIsString($extension);
        self::assertIsString($main);
        self::assertStringContainsString("import './extension/backup-notification-translation';", $main);
        self::assertStringContainsString("payload.source !== 'mucki-facility'", $extension);
        self::assertStringContainsString('this.$t(payload.snippet, parameters)', $extension);
        self::assertStringContainsString("Component.override('sw-notification-center-item'", $extension);
        self::assertStringContainsString("Component.override('sw-notifications'", $extension);
        self::assertStringContainsString('this.$super(\'getTranslatedMessage\', notification)', $extension);
    }

    public function testBackupNotificationSnippetsExistInEveryAdminLanguage(): void
    {
        $snippetPath = __DIR__ . '/../../src/Resources/app/administration/src/module/'
            . 'muwa-backup-repository/snippet/';
        $german = json_decode((string) file_get_contents($snippetPath . 'de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $english = json_decode((string) file_get_contents($snippetPath . 'en-GB.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'Backup „{name}“ wurde gestartet.',
            $german['muwa-backup-repository']['notification']['backupStarted']
        );
        self::assertSame(
            'Backup „{name}“ wurde erfolgreich abgeschlossen.',
            $german['muwa-backup-repository']['notification']['backupSucceeded']
        );
        self::assertSame(
            'Backup „{name}“ ist fehlgeschlagen: {error}',
            $german['muwa-backup-repository']['notification']['backupFailed']
        );
        self::assertSame(
            'Backup “{name}” started.',
            $english['muwa-backup-repository']['notification']['backupStarted']
        );
        self::assertSame(
            'Backup “{name}” completed successfully.',
            $english['muwa-backup-repository']['notification']['backupSucceeded']
        );
        self::assertSame(
            'Backup “{name}” failed: {error}',
            $english['muwa-backup-repository']['notification']['backupFailed']
        );
    }
}
