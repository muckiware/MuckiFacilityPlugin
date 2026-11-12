<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\ResticBinaryInfo;
use MuckiFacilityPlugin\Services\SettingsInterface;

/**
 * Runs against fake restic executables in a temp dir, so it needs neither Shopware nor restic.
 * The fake versions stay below 0.17, where muckiware/restic parses the plain text output.
 */
class ResticBinaryInfoTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/muwa-restic-info-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    public function testUsesDefaultBinaryWhenOwnPathIsDisabled(): void
    {
        $info = new ResticBinaryInfo($this->createSettings(false, '/opt/restic'));

        static::assertSame('restic', $info->getConfiguredBinaryPath());
    }

    public function testUsesDefaultBinaryWhenOwnPathIsEnabledButEmpty(): void
    {
        $info = new ResticBinaryInfo($this->createSettings(true, null));

        static::assertSame('restic', $info->getConfiguredBinaryPath());
    }

    public function testUsesOwnBinaryWhenEnabledAndSet(): void
    {
        $info = new ResticBinaryInfo($this->createSettings(true, '/opt/restic'));

        static::assertSame('/opt/restic', $info->getConfiguredBinaryPath());
    }

    public function testReportsPathAndSupportedVersionOfOwnBinary(): void
    {
        $binary = $this->createFakeRestic('restic 0.16.4 compiled with go1.21.6 on linux/amd64');

        $result = (new ResticBinaryInfo($this->createSettings(true, $binary)))->getInfo();

        static::assertSame($binary, $result['path']);
        static::assertTrue($result['ownPath']);
        static::assertSame('0.16.4', $result['version']);
        static::assertTrue($result['supported']);
        static::assertNull($result['error']);
    }

    public function testFlagsVersionBelowMinimumAsUnsupported(): void
    {
        $binary = $this->createFakeRestic('restic 0.14.0 compiled with go1.19.8 on linux/arm64');

        $result = (new ResticBinaryInfo($this->createSettings(true, $binary)))->getInfo();

        static::assertSame('0.14.0', $result['version']);
        static::assertFalse($result['supported']);
        static::assertSame(ResticBinaryInfo::MIN_SUPPORTED_VERSION, $result['minVersion']);
    }

    public function testReportsMissingBinary(): void
    {
        $missing = $this->tempDir.'/does-not-exist';

        $result = (new ResticBinaryInfo($this->createSettings(true, $missing)))->getInfo();

        static::assertSame($missing, $result['configuredPath']);
        static::assertNull($result['path']);
        static::assertNull($result['version']);
        static::assertSame('notFound', $result['error']);
    }

    public function testReportsUnreadableVersion(): void
    {
        $binary = $this->createFakeRestic('not a restic binary');

        $result = (new ResticBinaryInfo($this->createSettings(true, $binary)))->getInfo();

        static::assertSame($binary, $result['path']);
        static::assertNull($result['version']);
        static::assertSame('versionUnreadable', $result['error']);
    }

    public function testResolvesBareNameThroughPath(): void
    {
        $binary = $this->createFakeRestic('restic 0.16.4 compiled with go1.21.6 on linux/amd64');
        $originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->tempDir.PATH_SEPARATOR.$originalPath);

        try {
            $resolved = (new ResticBinaryInfo($this->createSettings(false, null)))->resolveBinaryPath('restic');
        } finally {
            putenv('PATH='.$originalPath);
        }

        static::assertSame($binary, $resolved);
    }

    private function createSettings(bool $useOwnPath, ?string $ownPath): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('hasOwnResticBinaryPath')->willReturn($useOwnPath);
        $settings->method('getOwnResticBinaryPath')->willReturn($ownPath);

        return $settings;
    }

    private function createFakeRestic(string $versionOutput): string
    {
        $binary = $this->tempDir.'/restic';
        file_put_contents($binary, "#!/bin/sh\necho '".$versionOutput."'\n");
        chmod($binary, 0755);

        return $binary;
    }
}
