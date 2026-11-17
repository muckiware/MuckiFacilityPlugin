<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Administration;

use PHPUnit\Framework\TestCase;

final class S3ConnectionAdministrationTest extends TestCase
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

        foreach (['muwa-s3-connection-list', 'muwa-s3-connection-detail'] as $page) {
            self::assertStringContainsString(
                "Shopware.Component.register('" . $page . "', () => import('./page/" . $page . "'))",
                $index
            );
        }
        self::assertStringContainsString(
            "Shopware.Component.register('muwa-s3-connection-select', () => import('./component/muwa-s3-connection-select'))",
            $index
        );
        self::assertStringContainsString("path: 's3-connection/create'", $index);
        self::assertStringContainsString("path: 's3-connection/detail/:id'", $index);
    }

    public function testListPageHasS3ConnectionTab(): void
    {
        $template = $this->read('page/muwa-backup-repository-list/muwa-backup-repository-list.html.twig');

        self::assertStringContainsString('key="s3ConnectionList"', $template);
        self::assertStringContainsString("params: { tab: 's3ConnectionList' }", $template);
        self::assertStringContainsString("<muwa-s3-connection-list\n", $template);
        self::assertStringContainsString("v-if=\"tab === 's3ConnectionList'\"", $template);
        self::assertStringContainsString("muwa.backup.repository.s3ConnectionCreate", $template);
        self::assertStringContainsString('muwa-backup-repository.s3Connection.createButton', $template);
    }

    public function testListComponentShowsColumnsAndGuardsDelete(): void
    {
        $component = $this->read('page/muwa-s3-connection-list/index.js');

        foreach (["property: 'name'", "property: 'provider'", "property: 'endpoint'"] as $column) {
            self::assertStringContainsString($column, $component);
        }
        self::assertStringNotContainsString("property: 'bucket'", $component);
        self::assertStringContainsString("Criteria.equals('mirrorS3ConnectionId', item.id)", $component);
        self::assertStringContainsString('muwa-backup-repository.s3Connection.deleteInUse', $component);

        $guard = strpos($component, "Criteria.equals('mirrorS3ConnectionId', item.id)");
        $delete = strpos($component, '.delete(item.id');
        self::assertNotFalse($guard);
        self::assertNotFalse($delete);
        self::assertLessThan($delete, $guard, 'usage must be checked before deleting');
    }

    public function testSelectOffersNoMirroringAsFirstOptionWithNullValue(): void
    {
        $component = $this->read('component/muwa-s3-connection-select/index.js');

        $optionsStart = strpos($component, 'connectionOptions() {');
        self::assertNotFalse($optionsStart);
        $options = substr($component, $optionsStart, 900);
        $default = strpos($options, 'NO_MIRROR_OPTION');
        $stored = strpos($options, 'this.connections.map(');
        self::assertNotFalse($default);
        self::assertNotFalse($stored);
        self::assertLessThan($stored, $default, '"no mirroring" must be the first option');
        self::assertStringContainsString("'muwa-backup-repository.s3Connection.noMirroring'", $options);

        self::assertStringContainsString(
            "this.\$emit('update:value', newValue === NO_MIRROR_OPTION ? null : newValue);",
            $component
        );
        self::assertStringContainsString('return this.value ?? NO_MIRROR_OPTION;', $component);
    }

    public function testSelectFollowsVersionPattern(): void
    {
        $template = $this->read('component/muwa-s3-connection-select/muwa-s3-connection-select.html.twig');
        $component = $this->read('component/muwa-s3-connection-select/index.js');

        self::assertStringContainsString('v-if="isV6700"', $template);
        self::assertStringContainsString('v-model="selectedValue"', $template);
        self::assertStringContainsString('v-if="isV6600 && !isV6700"', $template);
        self::assertStringContainsString('v-model:value="selectedValue"', $template);
        self::assertStringContainsString("repositoryFactory.create('muwa_s3_connection')", $component);
        self::assertStringNotContainsString('$listeners', $template . $component);
        self::assertStringNotContainsString('$scopedSlots', $template . $component);
        foreach (['id:', 'name:', 'value:', 'label:'] as $key) {
            self::assertStringContainsString($key, $component);
        }
    }

    public function testAclMapsS3ConnectionPrivilegesIntoExistingRoles(): void
    {
        $acl = $this->read('acl/index.js');
        $roles = [
            'viewer' => 'muwa_s3_connection:read',
            'editor' => 'muwa_s3_connection:update',
            'creator' => 'muwa_s3_connection:create',
            'deleter' => 'muwa_s3_connection:delete',
        ];

        self::assertStringNotContainsString("key: 'muwa_s3_connection'", $acl);
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
        $component = $this->read('page/muwa-s3-connection-detail/index.js');
        $template = $this->read('page/muwa-s3-connection-detail/muwa-s3-connection-detail.html.twig');

        self::assertStringContainsString("'/_action/muwa/s3-connection/save'", $component);
        self::assertStringContainsString("'/_action/muwa/s3-connection/test'", $component);
        self::assertStringContainsString('onClickTestConnection', $template);
        self::assertStringContainsString('v-model:value="testBucket"', $template);
        self::assertStringContainsString('muwa-backup-repository.s3Connection.testButton', $template);
        self::assertStringContainsString('muwa-backup-repository.s3Connection.secretSourceHelpText', $template);
        self::assertSame(2, substr_count($template, 'v-if="isV6700"'));
        self::assertSame(2, substr_count($template, 'v-if="isV6600 && !isV6700"'));
        self::assertStringNotContainsString('$listeners', $template . $component);
        self::assertStringNotContainsString('slot="', $template);
        self::assertDoesNotMatchRegularExpression('/\$tc\([^()]*,[^()]*,/', $template . $component);
    }

    public function testDetailPageHasProviderOptionsAndRequirements(): void
    {
        $component = $this->read('page/muwa-s3-connection-detail/index.js');
        $template = $this->read('page/muwa-s3-connection-detail/muwa-s3-connection-detail.html.twig');

        foreach (["'aws'", "'minio'", "'ceph'", "'other'"] as $provider) {
            self::assertStringContainsString($provider, $component);
        }
        self::assertStringContainsString('{ id: value, name: label, value, label }', $component);
        self::assertStringContainsString(':required="isRegionRequired"', $template);
        self::assertStringContainsString(':required="isEndpointRequired"', $template);
        self::assertStringContainsString("this.connection.provider === 'aws'", $component);
    }

    public function testDetailPageNeverBindsStoredSecret(): void
    {
        $component = $this->read('page/muwa-s3-connection-detail/index.js');
        $template = $this->read('page/muwa-s3-connection-detail/muwa-s3-connection-detail.html.twig');

        self::assertStringContainsString('v-model:value="secretAccessKey"', $template);
        self::assertDoesNotMatchRegularExpression('/\w\.secretAccessKey/', $template);
        self::assertDoesNotMatchRegularExpression('/(entity|connection)\.secretAccessKey/', $component);
        self::assertStringContainsString('secretAccessKey: this.secretAccessKey,', $component);
    }

    public function testChangingSecretSourceClearsTypedSecret(): void
    {
        $component = $this->read('page/muwa-s3-connection-detail/index.js');

        $watchStart = strpos($component, "'connection.secretSource'(");
        self::assertNotFalse($watchStart, 'watcher on connection.secretSource missing');
        $watcher = substr($component, $watchStart, (int) strpos($component, '},', $watchStart) - $watchStart);

        self::assertStringContainsString("this.secretAccessKey = '';", $watcher);
    }

    public function testSnippetsExistInBothLanguages(): void
    {
        $de = json_decode($this->read('snippet/de-DE.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode($this->read('snippet/en-GB.json'), true, 512, JSON_THROW_ON_ERROR);
        $deBlock = $de['muwa-backup-repository']['s3Connection'] ?? [];
        $enBlock = $en['muwa-backup-repository']['s3Connection'] ?? [];

        self::assertNotSame([], $deBlock);
        self::assertSame($this->flattenKeys($deBlock), $this->flattenKeys($enBlock));
        foreach ([$deBlock, $enBlock] as $block) {
            array_walk_recursive($block, static fn (mixed $value) => self::assertNotSame('', $value));
            self::assertStringContainsString('{names}', $block['deleteInUse']);
            self::assertStringContainsString('MUWA_S3_', $block['secretSourceHelpText']);
            self::assertStringContainsString('var/secrets', $block['secretSourceHelpText']);
        }

        self::assertSame('Keine Spiegelung', $deBlock['noMirroring']);
        self::assertSame('No mirroring', $enBlock['noMirroring']);
        self::assertSame('Amazon S3', $deBlock['providers']['aws']);
        self::assertSame('MinIO', $enBlock['providers']['minio']);
        self::assertSame('Ceph', $deBlock['providers']['ceph']);
        self::assertSame('Andere S3-kompatible', $deBlock['providers']['other']);
        self::assertSame('Other S3-compatible', $enBlock['providers']['other']);
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
