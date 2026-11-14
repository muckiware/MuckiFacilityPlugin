<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class RepositoryTakeoverAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';
    private const MODAL = 'component/muwa-backup-repository-add-modal/';

    private const ERROR_CODES = [
        'MUWA_TAKEOVER_INVALID_INPUT',
        'MUWA_TAKEOVER_PATH_NOT_FOUND',
        'MUWA_TAKEOVER_NOT_A_REPOSITORY',
        'MUWA_TAKEOVER_NOT_WRITABLE',
        'MUWA_TAKEOVER_DUPLICATE',
        'MUWA_TAKEOVER_PASSWORD_NOT_STORABLE',
        'MUWA_TAKEOVER_ACCESS_FAILED',
        'MUWA_TAKEOVER_SAVE_FAILED',
        'MUWA_TAKEOVER_IMPORT_FAILED',
    ];

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::MODULE . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function takeoverSnippets(string $language): array
    {
        $data = json_decode($this->read('snippet/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data['muwa-backup-repository']['takeover'] ?? null, $language . ' has no takeover block');

        return $data['muwa-backup-repository']['takeover'];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $path . '.'));
                continue;
            }
            $keys[] = $path;
        }

        return $keys;
    }

    public function testModalIsRegistered(): void
    {
        self::assertStringContainsString(
            "Shopware.Component.register('muwa-backup-repository-add-modal', () => import('./component/muwa-backup-repository-add-modal'));",
            $this->read('index.js')
        );
    }

    public function testModalIsLazyLoadableViaDefaultExport(): void
    {
        $modal = $this->read(self::MODAL . 'index.js');

        self::assertStringContainsString('export default', $modal);
        self::assertStringNotContainsString('Component.register(', $modal);
    }

    public function testCreateButtonOpensModalInsteadOfLink(): void
    {
        $template = $this->read('page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig');

        self::assertStringContainsString('@click="showAddModal = true"', $template);
        self::assertStringContainsString('<muwa-backup-repository-add-modal', $template);
        self::assertStringContainsString('@modal-close="showAddModal = false"', $template);
        self::assertStringNotContainsString(":routerLink=\"{name: 'muwa.backup.repository.create'}\"", $template);
        self::assertStringContainsString('showAddModal: false', $this->read('page/muwa-backup-repository-list/index.js'));
    }

    public function testModalPostsTakeoverAndRoutesToConfigTab(): void
    {
        $modal = $this->read(self::MODAL . 'index.js');

        self::assertStringContainsString('/_action/muwa/backup/repository/takeover', $modal);
        self::assertStringContainsString("tab: 'backupRepositoryConfig'", $modal);
        self::assertStringContainsString("name: 'muwa.backup.repository.create'", $modal);
        self::assertStringNotContainsString('console.', $modal);
    }

    public function testModalUsesTWithPlaceholdersNotTc(): void
    {
        $modal = $this->read(self::MODAL . 'index.js') . $this->read(self::MODAL . 'muwa-backup-repository-add-modal.html.twig');

        self::assertSame(0, preg_match('/\$tc\([^)]*,[^)]*,/', $modal), 'No $tc with three arguments allowed');
        self::assertStringContainsString("this.\$t('muwa-backup-repository.takeover.successMessage', { count:", $modal);
    }

    public function testAllTakeoverSnippetsExistInBothLanguages(): void
    {
        $en = $this->flattenKeys($this->takeoverSnippets('en-GB'));
        $de = $this->flattenKeys($this->takeoverSnippets('de-DE'));
        sort($en);
        sort($de);

        self::assertSame($en, $de);
        foreach (self::ERROR_CODES as $code) {
            self::assertContains('errors.' . $code, $en);
        }
        self::assertContains('errors.unknown', $en);
    }

    private const DETAIL = 'page/muwa-backup-repository-detail/';

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, "\n        " . $name . '(');
        if ($start === false) {
            $start = strpos($source, "\n        async " . $name . '(');
        }
        self::assertNotFalse($start, 'Method ' . $name . ' not found');
        $end = strpos($source, "\n        },", $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    public function testDetailLoadsSuggestionsOnlyForEditor(): void
    {
        $detail = $this->read(self::DETAIL . 'index.js');
        $body = $this->methodBody($detail, 'loadPathSuggestions');

        self::assertStringContainsString('/_action/muwa/backup/repository/path-suggestions', $detail);
        self::assertStringContainsString("acl.can('muwa_backup_repository.editor')", $body);
        self::assertLessThan(
            strpos($body, 'httpClient.post'),
            strpos($body, "acl.can('muwa_backup_repository.editor')")
        );
        self::assertStringContainsString('pathSuggestionsError', $body);
        self::assertStringContainsString('truncated', $body);
    }

    public function testAdoptAddsPathWithoutSaving(): void
    {
        $body = $this->methodBody($this->read(self::DETAIL . 'index.js'), 'onAdoptPathSuggestion');

        self::assertStringContainsString('backupPath: suggestion.path', $body);
        self::assertStringContainsString('compress: false', $body);
        self::assertStringContainsString('isDefault: false', $body);
        self::assertStringNotContainsString('httpClient', $body);
        self::assertStringNotContainsString('.save(', $body);
    }

    public function testDetailTemplateShowsSuggestions(): void
    {
        $template = $this->read(self::DETAIL . 'muwa-backup-repository-detail.html.twig');

        self::assertStringContainsString('muwa-backup-repository-detail-path-suggestions', $template);
        self::assertStringContainsString('openPathSuggestions', $template);
        self::assertStringContainsString('onAdoptPathSuggestion', $template);
        self::assertSame(0, preg_match('/\$tc\([^)]*,[^)]*,/', $template), 'No $tc with three arguments allowed');
    }

    public function testSuggestionSnippetsExistInBothLanguages(): void
    {
        $expected = ['title', 'adopt', 'stateDirectory', 'stateFile', 'stateMissing', 'lastSeen', 'host', 'error', 'truncated'];
        foreach (['de-DE', 'en-GB'] as $language) {
            $suggestions = $this->takeoverSnippets($language)['suggestions'] ?? null;
            self::assertIsArray($suggestions, $language . ' has no takeover.suggestions');
            foreach ($expected as $key) {
                self::assertNotEmpty($suggestions[$key] ?? null, $language . ' misses suggestions.' . $key);
            }
        }
    }

    public function testOpenPathSuggestionsToleratesNonArrayBackupPaths(): void
    {
        $detail = $this->read(self::DETAIL . 'index.js');
        $start = strpos($detail, "\n        openPathSuggestions()");
        self::assertNotFalse($start);
        $body = substr($detail, $start, 600);

        self::assertStringContainsString('Array.isArray(this.backupRepository.backupPaths)', $body);
        self::assertStringNotContainsString('backupPaths || []', $body);
    }
}
