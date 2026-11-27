<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class LongTextAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
    private const COMPONENT = 'component/muwa-long-text/';

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testComponentIsRegisteredAndImported(): void
    {
        $script = $this->read(self::MODULE . self::COMPONENT . 'index.js');

        self::assertStringContainsString("Component.register('muwa-long-text'", $script);
        self::assertStringContainsString("import './component/muwa-long-text';", $this->read(self::MODULE . 'index.js'));
    }

    public function testButtonOnlyForLongTextAndOpensModal(): void
    {
        $script = $this->read(self::MODULE . self::COMPONENT . 'index.js');
        $template = $this->read(self::MODULE . self::COMPONENT . 'muwa-long-text.html.twig');

        self::assertStringContainsString('this.normalizedText.length > this.maxLength', $script);
        self::assertStringContainsString("this.normalizedText.includes('\\n')", $script);
        self::assertMatchesRegularExpression('/<sw-button\s+v-if="isLong"/', $template);
        self::assertStringContainsString("\$t('muwa-backup-repository.longText.showMore')", $template);
        self::assertStringContainsString('<sw-modal', $template);
        self::assertStringContainsString('v-if="showModal"', $template);
        // help-text inside sw-modal breaks rendering in 6.7
        self::assertStringNotContainsString('help-text', $template);
    }

    public function testSnippets(): void
    {
        foreach (['de-DE' => 'mehr zeigen', 'en-GB' => 'show more'] as $locale => $label) {
            $data = json_decode($this->read(self::MODULE . 'snippet/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame($label, $data['muwa-backup-repository']['longText']['showMore']);
            self::assertNotEmpty($data['muwa-backup-repository']['longText']['modalTitle']);
        }
    }

    public function testUsedForCheckStatusAndS3Status(): void
    {
        $detail = $this->read(self::MODULE . 'page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig');
        self::assertMatchesRegularExpression('/<muwa-long-text\s+v-if="item.id === \'checkStatus\'"/', $detail);
        // Checks tab and S3 mirror history
        self::assertMatchesRegularExpression('/#column-checkStatus="\{item\}">\s*<muwa-long-text\s+:text="item.checkStatus"/', $detail);
        self::assertMatchesRegularExpression('/#column-message="\{ item \}">\s*<muwa-long-text\s+:text="item.message"/', $detail);

        $widget = $this->read(self::MODULE . 'component/muwa-s3-mirror-status-dashboard-widget/muwa-s3-mirror-status-dashboard-widget.html.twig');
        self::assertStringContainsString(':text="statusTitle(item)"', $widget);
        self::assertStringContainsString(':show-text="false"', $widget);
    }
}
