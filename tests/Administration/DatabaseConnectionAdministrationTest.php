<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class DatabaseConnectionAdministrationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/';

    private function read(string $relativePath): string
    {
        $content = file_get_contents(self::MODULE . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    public function testComponentsAndRoutesAreRegistered(): void
    {
        $index = $this->read('index.js');

        foreach (['muwa-database-connection-list', 'muwa-database-connection-detail'] as $page) {
            self::assertStringContainsString(
                "Shopware.Component.register('" . $page . "', () => import('./page/" . $page . "'))",
                $index
            );
        }
        self::assertStringContainsString(
            "Shopware.Component.register('muwa-database-connection-select', () => import('./component/muwa-database-connection-select'))",
            $index
        );
        self::assertStringContainsString("path: 'database-connection/create'", $index);
        self::assertStringContainsString("path: 'database-connection/detail/:id'", $index);
    }

    public function testListPageHasDatabaseConnectionTab(): void
    {
        $template = $this->read('page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig');

        self::assertStringContainsString('key="databaseConnectionList"', $template);
        self::assertStringContainsString("params: { tab: 'databaseConnectionList' }", $template);
        self::assertStringContainsString("<muwa-database-connection-list\n", $template);
        self::assertStringContainsString("v-if=\"tab === 'databaseConnectionList'\"", $template);
    }

    public function testListComponentShowsColumnsAndGuardsDelete(): void
    {
        $component = $this->read('page/muwa-database-connection-list/index.js');

        foreach (["property: 'name'", "property: 'type'", "property: 'host'", "property: 'databaseName'"] as $column) {
            self::assertStringContainsString($column, $component);
        }
        self::assertStringContainsString("Criteria.equals('databaseConnectionId', item.id)", $component);
        self::assertStringContainsString('muwa-backup-repository.databaseConnection.deleteInUse', $component);

        $guard = strpos($component, "Criteria.equals('databaseConnectionId', item.id)");
        $delete = strpos($component, '.delete(item.id');
        self::assertNotFalse($guard);
        self::assertNotFalse($delete);
        self::assertLessThan($delete, $guard, 'usage must be checked before deleting');
    }

    public function testSelectOffersDefaultConnectionAsFirstOptionWithNullValue(): void
    {
        $component = $this->read('component/muwa-database-connection-select/index.js');

        $optionsStart = strpos($component, 'connectionOptions() {');
        self::assertNotFalse($optionsStart);
        $options = substr($component, $optionsStart, 900);
        $default = strpos($options, 'DEFAULT_CONNECTION_OPTION');
        $stored = strpos($options, 'this.connections.map(');
        self::assertNotFalse($default);
        self::assertNotFalse($stored);
        self::assertLessThan($stored, $default, 'default connection must be the first option');
        self::assertStringContainsString("'muwa-backup-repository.databaseConnection.defaultConnection'", $options);

        // the sentinel only lives inside the select; outward the default connection is null
        self::assertStringContainsString(
            "this.\$emit('update:value', newValue === DEFAULT_CONNECTION_OPTION ? null : newValue);",
            $component
        );
        self::assertStringContainsString('return this.value ?? DEFAULT_CONNECTION_OPTION;', $component);
    }

    public function testSelectFollowsVersionPattern(): void
    {
        $template = $this->read('component/muwa-database-connection-select/muwa-database-connection-select.html.twig');
        $component = $this->read('component/muwa-database-connection-select/index.js');

        self::assertStringContainsString('v-if="isV6700"', $template);
        self::assertStringContainsString('v-model="selectedValue"', $template);
        self::assertStringContainsString('v-if="isV6600 && !isV6700"', $template);
        self::assertStringContainsString('v-model:value="selectedValue"', $template);
        self::assertStringNotContainsString('$listeners', $template . $component);
        self::assertStringNotContainsString('$scopedSlots', $template . $component);
        foreach (['id:', 'name:', 'value:', 'label:'] as $key) {
            self::assertStringContainsString($key, $component);
        }
    }

    public function testSelectOnlyShownForDatabaseBackupTypes(): void
    {
        foreach ([
            'page/muwa-backup-repository-create/muwa-backup-repository-create.html.twig',
            'page/muwa-backup-repository-detail/muwa-backup-repository-detail.html.twig',
        ] as $file) {
            $template = $this->read($file);
            $start = strpos($template, '<muwa-database-connection-select');
            self::assertNotFalse($start, $file);
            $tag = substr($template, $start, (int) strpos($template, '/>', $start) - $start);

            self::assertStringContainsString("v-if=\"backupRepository.type && backupRepository.type !== 'noneDatabase'\"", $tag, $file);
            self::assertStringContainsString('v-model:value="backupRepository.databaseConnectionId"', $tag, $file);
            // placed right after the dump path field
            self::assertGreaterThan(strpos($template, 'v-model:value="backupRepository.dbDumpPath"'), $start, $file);
        }
    }

    public function testCreatePageSendsConnectionIdToInitRoute(): void
    {
        $component = $this->read('page/muwa-backup-repository-create/index.js');

        self::assertStringContainsString('this.backupRepository.databaseConnectionId = null;', $component);
        self::assertStringContainsString('this.httpClient.post(this.requestInitRepository, this.backupRepository', $component);
    }

    public function testAclMapsDatabaseConnectionPrivilegesIntoExistingRoles(): void
    {
        $acl = $this->read('acl/index.js');
        $roles = [
            'viewer' => 'muwa_database_connection:read',
            'editor' => 'muwa_database_connection:update',
            'creator' => 'muwa_database_connection:create',
            'deleter' => 'muwa_database_connection:delete',
        ];

        self::assertSame(1, substr_count($acl, "key: 'muwa_backup_repository',\n    roles: {\n        viewer"));
        self::assertStringNotContainsString("key: 'muwa_database_connection'", $acl);
        foreach ($roles as $role => $privilege) {
            $roleStart = strpos($acl, '        ' . $role . ': {');
            self::assertNotFalse($roleStart, $role);
            $roleEnd = strpos($acl, 'dependencies:', $roleStart);
            self::assertStringContainsString("'" . $privilege . "'", substr($acl, $roleStart, $roleEnd - $roleStart), $role);
            self::assertSame(1, substr_count($acl, "'" . $privilege . "'"), $privilege);
        }
    }

    public function testDetailPageUsesActionRoutesAndVersionPattern(): void
    {
        $component = $this->read('page/muwa-database-connection-detail/index.js');
        $template = $this->read('page/muwa-database-connection-detail/muwa-database-connection-detail.html.twig');

        self::assertStringContainsString("'/_action/muwa/database-connection/save'", $component);
        self::assertStringContainsString("'/_action/muwa/database-connection/test'", $component);
        self::assertStringContainsString('onClickTestConnection', $template);
        self::assertStringContainsString('muwa-backup-repository.databaseConnection.testButton', $template);
        self::assertStringContainsString('muwa-backup-repository.databaseConnection.passwordSourceHelpText', $template);
        self::assertSame(2, substr_count($template, 'v-if="isV6700"'));
        self::assertSame(2, substr_count($template, 'v-if="isV6600 && !isV6700"'));
        self::assertStringNotContainsString('$listeners', $template . $component);
        self::assertStringNotContainsString('slot="', $template);
    }

    public function testDetailPageNeverBindsStoredPassword(): void
    {
        $component = $this->read('page/muwa-database-connection-detail/index.js');
        $template = $this->read('page/muwa-database-connection-detail/muwa-database-connection-detail.html.twig');

        // the password lives in its own data property, never on the loaded connection
        self::assertStringContainsString('v-model:value="password"', $template);
        self::assertDoesNotMatchRegularExpression('/\w\.password(?![A-Za-z])/', $template);
        self::assertDoesNotMatchRegularExpression('/(entity|connection|databaseConnection)\.password(?![A-Za-z])/', $component);
        self::assertStringContainsString('password: this.password,', $component);
    }

    public function testChangingPasswordSourceClearsTypedPassword(): void
    {
        $component = $this->read('page/muwa-database-connection-detail/index.js');

        $watchStart = strpos($component, "'connection.passwordSource'(");
        self::assertNotFalse($watchStart, 'watcher on connection.passwordSource missing');
        $watcher = substr($component, $watchStart, (int) strpos($component, '},', $watchStart) - $watchStart);

        // a typed password must never show up in the plain env/file text field
        self::assertStringContainsString("this.password = '';", $watcher);
        self::assertStringContainsString("this.passwordRepeat = '';", $watcher);
    }

    public function testSnippetsExistInBothLanguages(): void
    {
        $de = json_decode($this->read('snippet/de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode($this->read('snippet/en-GB.json'), true, 512, JSON_THROW_ON_ERROR);
        $deBlock = $de['muwa-backup-repository']['databaseConnection'] ?? [];
        $enBlock = $en['muwa-backup-repository']['databaseConnection'] ?? [];

        self::assertNotSame([], $deBlock);
        self::assertSame($this->flattenKeys($deBlock), $this->flattenKeys($enBlock));
        foreach ([$deBlock, $enBlock] as $block) {
            array_walk_recursive($block, static fn (mixed $value) => self::assertNotSame('', $value));
        }

        self::assertSame('Standardverbindung (System)', $deBlock['defaultConnection']);
        self::assertSame('Default connection (system)', $enBlock['defaultConnection']);
        self::assertSame('MySQL / MariaDB', $deBlock['types']['mysql']);
        self::assertSame('MySQL / MariaDB', $enBlock['types']['mysql']);
        self::assertStringContainsString('{names}', $deBlock['deleteInUse']);
        self::assertStringContainsString('{names}', $enBlock['deleteInUse']);
        foreach ([$deBlock, $enBlock] as $block) {
            self::assertStringContainsString('MUWA_DB_', $block['passwordSourceHelpText']);
            self::assertStringContainsString('var/secrets', $block['passwordSourceHelpText']);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function flattenKeys(array $block, string $prefix = ''): array
    {
        $keys = [];
        foreach ($block as $key => $value) {
            $keys[] = $prefix . $key;
            if (is_array($value)) {
                $keys = [...$keys, ...$this->flattenKeys($value, $prefix . $key . '.')];
            }
        }
        sort($keys);

        return $keys;
    }
}
