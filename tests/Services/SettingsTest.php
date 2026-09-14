<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use MuckiFacilityPlugin\Services\Settings as PluginSettings;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;

class SettingsTest extends TestCase
{
    private const PROJECT_DIR = '/var/www/html';
    private const DATESTAMP = '2026-08-21';

    public function testCheckSettingsIsEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);
        $helper = $this->createMock(PluginHelper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $settingsClass = new PluginSettings($config, $kernel, $helper, $logger);
        $config->method('getBool')->willReturn(true);
        $isEnabled1 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled1, 'isEnabled method should return boolean');
        static::assertTrue($isEnabled1, 'isEnabled method should return true');
    }

    public function testCheckSettingsIsNotEnabled(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $config = $this->createMock(SystemConfigService::class);
        $helper = $this->createMock(PluginHelper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $settingsClass = new PluginSettings($config, $kernel, $helper, $logger);

        $config->method('getBool')->willReturn(false);
        $isEnabled2 = $settingsClass->isEnabled();
        static::assertIsBool($isEnabled2, 'isEnabled method should return boolean');
        static::assertFalse($isEnabled2, 'isEnabled method should return false');
    }

    public function testGetBackupPathWithoutOwnDumpPathReturnsDefault(): void
    {
        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup',
            $this->createSettings()->getBackupPath(),
            'Without own dump path the default backup path should be returned'
        );
    }

    public function testGetBackupPathWithoutOwnDumpPathAndSubFolderReturnsDefault(): void
    {
        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup/'.self::DATESTAMP,
            $this->createSettings()->getBackupPath(true),
            'Without own dump path the default backup path with datestamp sub folder should be returned'
        );
    }

    /**
     * @dataProvider emptyDumpPathProvider
     */
    public function testGetBackupPathWithEmptyOwnDumpPathReturnsDefault(?string $dumpPath): void
    {
        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup',
            $this->createSettings()->getBackupPath(false, $dumpPath),
            'An empty own dump path should fall back to the default backup path'
        );
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public static function emptyDumpPathProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    public function testGetBackupPathWithAbsoluteOwnDumpPath(): void
    {
        static::assertSame(
            '/mnt/backup/dump',
            $this->createSettings()->getBackupPath(false, '/mnt/backup/dump'),
            'An own dump path starting with a slash should be used as it is'
        );
    }

    public function testGetBackupPathWithRelativeOwnDumpPath(): void
    {
        static::assertSame(
            self::PROJECT_DIR.'/custom/dump',
            $this->createSettings()->getBackupPath(false, 'custom/dump'),
            'A relative own dump path should be resolved against the project dir'
        );
    }

    public function testGetBackupPathWithOwnDumpPathTrimsWhitespaceAndTrailingSlash(): void
    {
        static::assertSame(
            '/mnt/backup/dump',
            $this->createSettings()->getBackupPath(false, '  /mnt/backup/dump/  '),
            'Whitespace and trailing slashes of the own dump path should be removed'
        );
    }

    public function testGetBackupPathWithOwnDumpPathAndSubFolder(): void
    {
        static::assertSame(
            '/mnt/backup/dump/'.self::DATESTAMP,
            $this->createSettings()->getBackupPath(true, '/mnt/backup/dump'),
            'The datestamp sub folder should be appended to the own dump path'
        );
    }

    /**
     * @dataProvider dangerousDumpPathProvider
     */
    public function testGetBackupPathRejectsDangerousOwnDumpPath(string $dumpPath): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');

        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup',
            $this->createSettings($logger)->getBackupPath(false, $dumpPath),
            'A dangerous own dump path should fall back to the default backup path'
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function dangerousDumpPathProvider(): array
    {
        return [
            'root' => ['/'],
            'project dir' => [self::PROJECT_DIR],
            'project dir with trailing slash' => [self::PROJECT_DIR.'/'],
            'parent of project dir' => ['/var/www'],
            'relative pointing to project dir' => ['.'],
        ];
    }

    public function testGetBackupPathEnsuresTheDirectoryExists(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(self::PROJECT_DIR);
        $config = $this->createMock(SystemConfigService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $helper = $this->createMock(PluginHelper::class);
        $helper->expects(static::once())
            ->method('ensureDirectoryExists')
            ->with('/mnt/backup/dump')
            ->willReturn(true);

        $settingsClass = new PluginSettings($config, $kernel, $helper, $logger);
        $settingsClass->getBackupPath(false, '/mnt/backup/dump');
    }

    public function testGetBackupPathFallsBackToDefaultWhenOwnDumpPathCannotBeCreated(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(self::PROJECT_DIR);
        $config = $this->createMock(SystemConfigService::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');

        $helper = $this->createMock(PluginHelper::class);
        $helper->method('ensureDirectoryExists')->willReturnCallback(
            static fn (string $path): bool => $path === self::PROJECT_DIR.'/var/db/backup'
        );

        $settingsClass = new PluginSettings($config, $kernel, $helper, $logger);

        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup',
            $settingsClass->getBackupPath(false, '/mnt/backup/dump'),
            'An own dump path which cannot be created should fall back to the default backup path'
        );
    }

    public function testGetDefaultBackupPathLogsAnErrorWhenItCannotBeCreated(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(self::PROJECT_DIR);
        $config = $this->createMock(SystemConfigService::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');

        $helper = $this->createMock(PluginHelper::class);
        $helper->method('ensureDirectoryExists')->willReturn(false);

        $settingsClass = new PluginSettings($config, $kernel, $helper, $logger);

        static::assertSame(
            self::PROJECT_DIR.'/var/db/backup',
            $settingsClass->getDefaultBackupPath(),
            'The default backup path should still be returned when it cannot be created'
        );
    }

    private function createSettings(?LoggerInterface $logger = null): PluginSettings
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(self::PROJECT_DIR);

        $config = $this->createMock(SystemConfigService::class);

        $helper = $this->createMock(PluginHelper::class);
        $helper->method('getCurrentDateTimeStr')->willReturn(self::DATESTAMP);
        $helper->method('ensureDirectoryExists')->willReturn(true);

        return new PluginSettings(
            $config,
            $kernel,
            $helper,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
