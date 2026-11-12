<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class RestoreDirectoryTreeAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::MODULE . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    public function testComponentIsRegisteredAsync(): void
    {
        self::assertStringContainsString(
            "Shopware.Component.register('muwa-restore-directory-tree', () => import('./component/muwa-restore-directory-tree'))",
            $this->read('index.js')
        );
    }

    public function testPanelIsGatedByRestoreAcl(): void
    {
        $template = $this->read('page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig');
        $cardStart = strpos($template, 'positionIdentifier="muwaBackupRepositoryRestoreDirectory"');

        self::assertNotFalse($cardStart);
        self::assertGreaterThan(strrpos($template, '</sw-entity-listing>'), $cardStart);
        $card = substr($template, $cardStart, 400);
        self::assertStringContainsString("acl.can('muwa_backup_repository.restore')", $card);
        self::assertStringContainsString('ref="restoreDirectoryTree"', $template);
    }

    public function testRefreshAlsoReloadsTree(): void
    {
        self::assertStringContainsString(
            'this.$refs.restoreDirectoryTree?.refresh()',
            $this->read('page/muwa-backup-repository-detail/index.js')
        );
    }

    public function testComponentUsesRoutesAndTokenDownload(): void
    {
        $component = $this->read('component/muwa-restore-directory-tree/index.js');

        self::assertStringContainsString('/_action/muwa/restore/directory', $component);
        self::assertStringContainsString('/_action/muwa/restore/download/prepare', $component);
        self::assertStringContainsString('accessToken', $component);
    }

    public function testSnippetsExistInBothLanguages(): void
    {
        $keys = ['title', 'emptyState', 'notConfigured', 'notAllowed', 'truncated', 'downloadStarted', 'errorTitle', 'loadError', 'downloadError'];
        $exact = [
            'de-DE' => ['title' => 'Wiederhergestellte Dateien', 'notAllowed' => 'Restore Path nicht zulässig – Anzeige gesperrt.'],
            'en-GB' => ['title' => 'Restored files', 'notAllowed' => 'Restore path not allowed – view blocked.'],
        ];

        foreach ($exact as $language => $values) {
            $snippets = json_decode($this->read('snippet/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $block = $snippets['muwa-backup-repository']['restoreDirectory'] ?? [];

            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $block, $language . ' ' . $key);
                self::assertNotSame('', $block[$key]);
            }
            foreach ($values as $key => $value) {
                self::assertSame($value, $block[$key]);
            }
        }
    }
}
