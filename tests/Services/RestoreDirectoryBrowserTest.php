<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Core\DumpPathRejection;
use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\RestoreDirectoryBrowser;
use MuckiFacilityPlugin\Services\SettingsInterface;

class RestoreDirectoryBrowserTest extends TestCase
{
    private const ID = '0191a0a0a0a07000a000a0a0a0a0a0a0';

    private string $base;
    private string $restore;
    /** @var SettingsInterface&MockObject */
    private SettingsInterface $settings;
    /** @var BackupRepository&MockObject */
    private BackupRepository $repositories;
    /** @var BackupRepositoryEntity&MockObject */
    private BackupRepositoryEntity $entity;
    private RestoreDirectoryBrowser $browser;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/muwa-restore-' . uniqid();
        $this->restore = $this->base . '/restore';
        mkdir($this->restore, 0777, true);

        $this->entity = $this->createMock(BackupRepositoryEntity::class);
        $this->entity->method('getRestorePath')->willReturnCallback(fn (): string => $this->restore);
        $this->entity->method('getRepositoryPath')->willReturn($this->base . '/repo');
        $this->entity->method('getDbDumpPath')->willReturn(null);

        $this->repositories = $this->createMock(BackupRepository::class);
        $this->repositories->method('getBackupRepositoryById')
            ->willReturnCallback(fn (string $id): ?BackupRepositoryEntity => $id === self::ID ? $this->entity : null);

        $this->settings = $this->createMock(SettingsInterface::class);
        $this->settings->method('resolveDumpPathValue')->willReturnArgument(0);
        $this->settings->method('getRestorePathRejection')->willReturn(null);

        $this->browser = new RestoreDirectoryBrowser($this->repositories, $this->settings);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->base);
    }

    public function testListsRootWithDumpsDownloadableAndOtherFilesNot(): void
    {
        $dump = '2026-11-12_shop.backup.sql.tar.gz';
        mkdir($this->restore . '/var/db/backup', 0777, true);
        file_put_contents($this->restore . '/var/db/backup/' . $dump, 'abc');
        file_put_contents($this->restore . '/notes.txt', 'x');

        $root = $this->browser->listDirectory(self::ID, '');

        static::assertSame('ok', $root['state']);
        static::assertNull($root['rejection']);
        static::assertSame('', $root['path']);
        static::assertNull($root['parent']);
        static::assertSame(['var'], array_column($root['directories'], 'name'));
        static::assertSame(['notes.txt'], array_column($root['files'], 'name'));
        static::assertFalse($root['files'][0]['downloadable']);
        static::assertFalse($root['truncated']);

        $sub = $this->browser->listDirectory(self::ID, 'var/db/backup');

        static::assertSame('var/db', $sub['parent']);
        static::assertSame($dump, $sub['files'][0]['name']);
        static::assertTrue($sub['files'][0]['downloadable']);
        static::assertSame('var/db/backup/' . $dump, $sub['files'][0]['path']);
        static::assertSame(3, $sub['files'][0]['size']);
        static::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $sub['files'][0]['modifiedAt']));
    }

    public function testParentOfHiddenDirectoryKeepsLeadingDot(): void
    {
        mkdir($this->restore . '/.hidden/sub', 0777, true);

        static::assertSame('', $this->browser->listDirectory(self::ID, '.hidden')['parent']);
        static::assertSame('.hidden', $this->browser->listDirectory(self::ID, '.hidden/sub')['parent']);
    }

    public function testNullRelativePathListsRoot(): void
    {
        static::assertSame('', $this->browser->listDirectory(self::ID, null)['path']);
    }

    public function testAllThreeDumpSuffixesAreDownloadable(): void
    {
        $expected = [
            'a.backup.sql.tar.gz' => true,
            'b.backup.sql.gz' => true,
            'c.backup.sql' => true,
            'dump.sql' => false,
            'x.backup.sql.bak' => false,
        ];
        foreach (array_keys($expected) as $name) {
            file_put_contents($this->restore . '/' . $name, 'x');
        }

        $files = $this->browser->listDirectory(self::ID, '')['files'];

        static::assertSame($expected, array_column($files, 'downloadable', 'name'));
    }

    public function testEmptyRestorePathReturnsNotConfigured(): void
    {
        $entity = $this->createMock(BackupRepositoryEntity::class);
        $entity->method('getRestorePath')->willReturn('  ');
        $repositories = $this->createMock(BackupRepository::class);
        $repositories->method('getBackupRepositoryById')->willReturn($entity);
        $browser = new RestoreDirectoryBrowser($repositories, $this->settings);

        $result = $browser->listDirectory(self::ID, '');

        static::assertSame('notConfigured', $result['state']);
        static::assertSame([], $result['directories']);
        static::assertSame([], $result['files']);
    }

    public function testRejectedRestorePathReturnsNotAllowed(): void
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('getRestorePathRejection')->willReturn(DumpPathRejection::BLOCKED_PATH);
        $browser = new RestoreDirectoryBrowser($this->repositories, $settings);

        $result = $browser->listDirectory(self::ID, '');

        static::assertSame('notAllowed', $result['state']);
        static::assertSame('blockedPath', $result['rejection']);
        static::assertSame([], $result['files']);
    }

    public function testRestorePathSymlinkToRejectedTargetIsNotAllowed(): void
    {
        $target = $this->base . '/blocked';
        mkdir($target);
        file_put_contents($target . '/x.backup.sql', 'secret');
        $link = $this->base . '/link';
        symlink($target, $link);
        $realTarget = realpath($target);

        $entity = $this->createMock(BackupRepositoryEntity::class);
        $entity->method('getRestorePath')->willReturn($link);
        $repositories = $this->createMock(BackupRepository::class);
        $repositories->method('getBackupRepositoryById')->willReturn($entity);
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('resolveDumpPathValue')->willReturnArgument(0);
        $settings->method('getRestorePathRejection')->willReturnCallback(
            static fn (string $path): ?DumpPathRejection => $path === $realTarget ? DumpPathRejection::BLOCKED_PATH : null
        );
        $browser = new RestoreDirectoryBrowser($repositories, $settings);

        $result = $browser->listDirectory(self::ID, '');

        static::assertSame('notAllowed', $result['state']);
        static::assertSame('blockedPath', $result['rejection']);
        static::assertSame([], $result['files']);

        $this->expectException(InvalidBrowsePathException::class);
        $browser->resolveDownloadFile(self::ID, 'x.backup.sql');
    }

    public function testMissingRestoreDirectoryReturnsEmptyState(): void
    {
        $this->removeRecursive($this->restore);

        $result = $this->browser->listDirectory(self::ID, '');

        static::assertSame('empty', $result['state']);
        static::assertSame([], $result['directories']);
    }

    public function testExistingEmptyRestoreDirectoryStaysOk(): void
    {
        $result = $this->browser->listDirectory(self::ID, '');

        static::assertSame('ok', $result['state']);
        static::assertSame([], $result['files']);
    }

    /**
     * @dataProvider invalidRelativePathProvider
     */
    public function testInvalidRelativePathThrows(string $path): void
    {
        $this->expectException(InvalidBrowsePathException::class);

        $this->browser->listDirectory(self::ID, $path);
    }

    /**
     * @dataProvider invalidRelativePathProvider
     */
    public function testInvalidRelativePathThrowsOnDownload(string $path): void
    {
        $this->expectException(InvalidBrowsePathException::class);

        $this->browser->resolveDownloadFile(self::ID, $path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRelativePathProvider(): array
    {
        return [
            'parent' => ['../x'],
            'nested parent' => ['a/../../x'],
            'dot segment' => ['a/./b'],
            'absolute' => ['/etc'],
            'null byte' => ["a\0b"],
        ];
    }

    public function testNonExistingSubdirectoryThrows(): void
    {
        $this->expectException(InvalidBrowsePathException::class);

        $this->browser->listDirectory(self::ID, 'nope');
    }

    public function testSymlinkOutsideRestorePathIsRejected(): void
    {
        $outside = $this->base . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/x.backup.sql', 'secret');
        symlink($outside, $this->restore . '/out');

        static::assertSame([], $this->browser->listDirectory(self::ID, '')['directories']);

        try {
            $this->browser->listDirectory(self::ID, 'out');
            static::fail('Listing through an outside symlink must throw');
        } catch (InvalidBrowsePathException) {
            static::assertTrue(true);
        }

        $this->expectException(InvalidBrowsePathException::class);
        $this->browser->resolveDownloadFile(self::ID, 'out/x.backup.sql');
    }

    public function testSymlinkedFileIsNotListed(): void
    {
        file_put_contents($this->base . '/secret.backup.sql', 'secret');
        symlink($this->base . '/secret.backup.sql', $this->restore . '/link.backup.sql');

        static::assertSame([], $this->browser->listDirectory(self::ID, '')['files']);
    }

    public function testResolveDownloadFileReturnsRealPathOfDump(): void
    {
        mkdir($this->restore . '/sub');
        file_put_contents($this->restore . '/sub/a.backup.sql.gz', 'x');

        $path = $this->browser->resolveDownloadFile(self::ID, 'sub/a.backup.sql.gz');

        static::assertSame(realpath($this->restore . '/sub/a.backup.sql.gz'), $path);
    }

    public function testResolveDownloadFileRejectsNonDumpAndDirectories(): void
    {
        file_put_contents($this->restore . '/notes.txt', 'x');
        mkdir($this->restore . '/dir.backup.sql');

        foreach (['notes.txt', 'dir.backup.sql', 'missing.backup.sql', ''] as $path) {
            try {
                $this->browser->resolveDownloadFile(self::ID, $path);
                static::fail('Expected exception for "' . $path . '"');
            } catch (InvalidBrowsePathException) {
                static::assertTrue(true);
            }
        }
    }

    public function testResolveDownloadFileRequiresOkState(): void
    {
        $this->removeRecursive($this->restore);

        $this->expectException(InvalidBrowsePathException::class);

        $this->browser->resolveDownloadFile(self::ID, 'a.backup.sql');
    }

    public function testUnknownRepositoryThrows(): void
    {
        $this->expectException(InvalidBrowsePathException::class);

        $this->browser->listDirectory('unknown', '');
    }

    public function testListingIsTruncatedAtMaxEntries(): void
    {
        for ($i = 0; $i <= RestoreDirectoryBrowser::MAX_ENTRIES; $i++) {
            touch($this->restore . '/f' . $i . '.backup.sql');
        }

        $result = $this->browser->listDirectory(self::ID, '');

        static::assertTrue($result['truncated']);
        static::assertCount(RestoreDirectoryBrowser::MAX_ENTRIES, $result['files']);
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeRecursive($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
