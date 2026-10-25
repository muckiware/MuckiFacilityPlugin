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

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Core\DumpPathRejection;
use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\DirectoryBrowser;
use MuckiFacilityPlugin\Services\SettingsInterface;

class DirectoryBrowserTest extends TestCase
{
    private string $root;
    private SettingsInterface $settings;
    private DirectoryBrowser $browser;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/muwa-browser-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->root . '/A');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->root);
        $this->settings = $this->createMock(SettingsInterface::class);
        $this->settings->method('resolveDumpPathValue')->willReturnArgument(0);

        $this->browser = new DirectoryBrowser($this->settings, $kernel);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->root);
    }

    public function testListReturnsSortedSubdirectoriesOnly(): void
    {
        foreach (['b', 'c10', 'c2'] as $name) {
            mkdir($this->root . '/' . $name);
        }
        file_put_contents($this->root . '/file.txt', 'x');

        $result = $this->browser->listDirectories($this->root);

        static::assertSame(['A', 'b', 'c2', 'c10'], array_column($result['directories'], 'name'));
        static::assertFalse($result['truncated']);
    }

    public function testListFilesReturnsReadableShellScriptsOnly(): void
    {
        file_put_contents($this->root . '/before.sh', '#!/bin/bash');
        file_put_contents($this->root . '/ignored.php', '<?php');

        $result = $this->browser->listFiles($this->root, ['sh']);

        static::assertSame(['before.sh'], array_column($result['files'], 'name'));
        static::assertSame($this->root . '/before.sh', $result['files'][0]['path']);
        static::assertTrue($result['files'][0]['readable']);
        static::assertSame(['A'], array_column($result['directories'], 'name'));
    }

    public function testListFilesUsesParentWhenCurrentValueIsAFile(): void
    {
        $file = $this->root . '/before.sh';
        file_put_contents($file, '#!/bin/bash');

        $result = $this->browser->listFiles($file, ['sh']);

        static::assertSame($this->root, $result['path']);
        static::assertSame(['before.sh'], array_column($result['files'], 'name'));
    }

    public function testListSkipsDirectoriesWithInvalidUtf8Names(): void
    {
        if (@mkdir($this->root . "/B\xfccher") === false) {
            $this->markTestSkipped('Filesystem refuses non UTF-8 names');
        }

        $result = $this->browser->listDirectories($this->root);

        static::assertSame(['A'], array_column($result['directories'], 'name'));
        static::assertNotFalse(json_encode($result));
    }

    public function testListDefaultsToProjectDir(): void
    {
        static::assertSame($this->root, $this->browser->listDirectories(null)['path']);
    }

    public function testListResolvesRelativePathAgainstProjectDir(): void
    {
        static::assertSame($this->root . '/A', $this->browser->listDirectories('A')['path']);
    }

    public function testListFallsBackToNearestExistingParent(): void
    {
        $result = $this->browser->listDirectories($this->root . '/A/neu/tiefer');

        static::assertSame($this->root . '/A', $result['path']);
        static::assertSame($this->root, $result['parent']);
    }

    public function testListNormalizesSlashes(): void
    {
        static::assertSame(
            $this->root . '/A',
            $this->browser->listDirectories($this->root . '//A/')['path']
        );
    }

    public function testListMarksHiddenDirectories(): void
    {
        mkdir($this->root . '/.git');

        $byName = $this->indexByName($this->browser->listDirectories($this->root));

        static::assertTrue($byName['.git']['hidden']);
        static::assertFalse($byName['A']['hidden']);
    }

    public function testListKeepsSymlinkPathLogical(): void
    {
        symlink($this->root . '/A', $this->root . '/link');

        static::assertSame(
            $this->root . '/link',
            $this->browser->listDirectories($this->root . '/link')['path']
        );
        $byName = $this->indexByName($this->browser->listDirectories($this->root));
        static::assertTrue($byName['link']['link']);
        static::assertFalse($byName['A']['link']);
    }

    public function testListMarksUnreadableDirectories(): void
    {
        $this->skipIfRoot();
        mkdir($this->root . '/locked');
        chmod($this->root . '/locked', 0000);

        $byName = $this->indexByName($this->browser->listDirectories($this->root));

        static::assertFalse($byName['locked']['readable']);
        static::assertTrue($byName['A']['readable']);
    }

    public function testListThrowsForUnreadableStartDirectory(): void
    {
        $this->skipIfRoot();
        mkdir($this->root . '/locked');
        chmod($this->root . '/locked', 0000);

        $this->expectException(InvalidBrowsePathException::class);
        $this->browser->listDirectories($this->root . '/locked');
    }

    public function testListIsTruncatedAtMaxEntries(): void
    {
        for ($i = 0; $i < DirectoryBrowser::MAX_ENTRIES + 5; $i++) {
            mkdir($this->root . '/many/d' . $i, 0777, true);
        }

        $result = $this->browser->listDirectories($this->root . '/many');

        static::assertCount(DirectoryBrowser::MAX_ENTRIES, $result['directories']);
        static::assertTrue($result['truncated']);
    }

    /**
     * @dataProvider invalidInputProvider
     */
    public function testListRejectsInvalidInput(string $path): void
    {
        $this->expectException(InvalidBrowsePathException::class);
        $this->browser->listDirectories($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalidInputProvider(): array
    {
        return [
            'null byte' => ["/tmp\0x"],
            'newline' => ["/tmp\nx"],
            'delete char' => ["/tmp\x7Fx"],
            'dot dot' => ['/tmp/../etc'],
            'single dot' => ['/tmp/./x'],
            'too long' => [str_repeat('a', 4097)],
        ];
    }

    /**
     * @dataProvider controlCharacterProvider
     */
    public function testCheckDumpPathRejectsControlCharacters(string $dumpPath): void
    {
        $this->expectException(InvalidBrowsePathException::class);
        $this->browser->checkDumpPath($dumpPath, null, null);
    }

    /**
     * @return array<string, array{string}>
     */
    public function controlCharacterProvider(): array
    {
        return [
            'null byte' => ["/tmp\0x"],
            'newline' => ["/tmp\nx"],
            'delete char' => ["/tmp\x7Fx"],
            'too long' => [str_repeat('a', 4097)],
        ];
    }

    public function testListRootHasNoParent(): void
    {
        $result = $this->browser->listDirectories('/');

        static::assertSame('/', $result['path']);
        static::assertNull($result['parent']);
    }

    public function testCheckDumpPathReturnsRejection(): void
    {
        $this->settings->method('getOwnDumpPathRejection')->willReturn(DumpPathRejection::BLOCKED_PATH);

        $result = $this->browser->checkDumpPath('/etc', null, null);

        static::assertSame('blockedPath', $result['rejection']);
        static::assertSame('/etc', $result['resolvedPath']);
    }

    public function testCheckDumpPathReportsWritableForMissingDirectoryWithWritableParent(): void
    {
        $target = $this->root . '/A/neu';

        $result = $this->browser->checkDumpPath($target, null, null);

        static::assertNull($result['rejection']);
        static::assertTrue($result['writable']);
        static::assertSame($target, $result['resolvedPath']);
        static::assertDirectoryDoesNotExist($target);
    }

    public function testCheckDumpPathReportsNotWritable(): void
    {
        $this->skipIfRoot();
        mkdir($this->root . '/ro');
        chmod($this->root . '/ro', 0555);

        $result = $this->browser->checkDumpPath($this->root . '/ro', null, null);

        static::assertFalse($result['writable']);
    }

    public function testCheckDumpPathEmptyValue(): void
    {
        static::assertSame(
            ['rejection' => null, 'writable' => true, 'resolvedPath' => null],
            $this->browser->checkDumpPath('', null, null)
        );
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('Permission checks are not meaningful as root.');
        }
    }

    /**
     * @param array{directories: list<array<string, mixed>>} $result
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $result): array
    {
        $byName = [];
        foreach ($result['directories'] as $entry) {
            $byName[$entry['name']] = $entry;
        }

        return $byName;
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
        chmod($path, 0777);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeRecursive($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
