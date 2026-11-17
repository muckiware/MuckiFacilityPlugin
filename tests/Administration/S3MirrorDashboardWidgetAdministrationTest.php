<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class S3MirrorDashboardWidgetAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
    private const WIDGET = 'component/muwa-s3-mirror-status-dashboard-widget/';
    private const CONFIG = __DIR__ . '/../../src/Resources/config/config.xml';

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function snippets(string $locale): array
    {
        $data = json_decode($this->read(self::MODULE . 'snippet/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data['muwa-backup-repository'];
    }

    public function testConfigHasSeparateSwitchDefaultOff(): void
    {
        $config = simplexml_load_string($this->read(self::CONFIG));
        self::assertNotFalse($config);

        $fields = $config->xpath('//input-field[name="showS3MirrorDashboardWidget"]');
        self::assertIsArray($fields);
        self::assertCount(1, $fields);
        self::assertSame('bool', (string) $fields[0]['type']);
        self::assertSame('false', (string) $fields[0]->defaultValue);

        // the backup status widget keeps its own, independent switch
        self::assertCount(1, (array) $config->xpath('//input-field[name="showDashboardWidget"]'));
    }

    public function testWidgetReadsOwnSwitchAndAcl(): void
    {
        $script = $this->read(self::MODULE . self::WIDGET . 'index.js');

        self::assertStringContainsString("Component.register('muwa-s3-mirror-status-dashboard-widget'", $script);
        self::assertStringContainsString('.showS3MirrorDashboardWidget`', $script);
        self::assertStringNotContainsString('.showDashboardWidget`', $script);
        self::assertStringContainsString("this.acl.can('muwa_backup_repository.viewer')", $script);
        self::assertStringContainsString("value === true || value === 'true'", $script);
    }

    public function testWidgetOnlyLoadsActiveMirroredRepositories(): void
    {
        $script = $this->read(self::MODULE . self::WIDGET . 'index.js');

        self::assertStringContainsString("Criteria.equals('active', true)", $script);
        self::assertStringContainsString("Criteria.not('AND', [Criteria.equals('mirrorS3ConnectionId', null)])", $script);
        self::assertStringContainsString("criteria.getAssociation('uploads')", $script);
        self::assertStringContainsString("Criteria.equals('status', 'succeeded')", $script);
    }

    public function testStatusShowsMessageAndOutdatedWarning(): void
    {
        $script = $this->read(self::MODULE . self::WIDGET . 'index.js');

        self::assertStringContainsString("criteria.getAssociation('backupRepositoryChecks')", $script);
        self::assertStringContainsString('isOutdated(item)', $script);
        self::assertStringContainsString("return 'warning';", $script);
        self::assertStringContainsString("succeeded: 'success'", $script);
        self::assertStringContainsString("failed: 'danger'", $script);
        self::assertStringContainsString('item.message', $script);
    }

    public function testWidgetIsRegisteredAndPlacedOnDashboard(): void
    {
        $module = $this->read(self::MODULE . 'index.js');
        $dashboard = $this->read(self::MODULE . 'extension/sw-dashboard-index/sw-dashboard-index.html.twig');

        self::assertStringContainsString("import './component/muwa-s3-mirror-status-dashboard-widget';", $module);
        self::assertStringContainsString('<muwa-s3-mirror-status-dashboard-widget />', $dashboard);
        self::assertStringContainsString('<muwa-backup-status-dashboard-widget />', $dashboard);
    }

    public function testTemplateUsesVisibilityGuardAndSnippets(): void
    {
        $template = $this->read(self::MODULE . self::WIDGET . 'muwa-s3-mirror-status-dashboard-widget.html.twig');

        self::assertStringContainsString('v-if="isVisible"', $template);
        self::assertStringContainsString("muwa-backup-repository.s3Dashboard.title", $template);
        self::assertStringContainsString("muwa-backup-repository.s3Dashboard.emptyTitle", $template);
        self::assertStringContainsString('#column-status', $template);
    }

    public function testSnippetsExistInBothLanguages(): void
    {
        $de = $this->snippets('de-DE');
        $en = $this->snippets('en-GB');

        self::assertArrayHasKey('s3Dashboard', $de);
        self::assertSame(array_keys($de['s3Dashboard']), array_keys($en['s3Dashboard']));
        foreach (['pending', 'outdated'] as $key) {
            self::assertArrayHasKey($key, $de['s3Dashboard'], $key);
        }

        $script = $this->read(self::MODULE . self::WIDGET . 'index.js')
            . $this->read(self::MODULE . self::WIDGET . 'muwa-s3-mirror-status-dashboard-widget.html.twig');
        preg_match_all('/muwa-backup-repository\.s3Dashboard\.(\w+)/', $script, $matches);
        foreach (array_unique($matches[1]) as $key) {
            self::assertArrayHasKey($key, $de['s3Dashboard'], $key);
            self::assertArrayHasKey($key, $en['s3Dashboard'], $key);
        }
        foreach (['skipped', 'succeeded', 'failed'] as $status) {
            self::assertArrayHasKey($status, $de['mirror']['status']);
        }
    }
}
