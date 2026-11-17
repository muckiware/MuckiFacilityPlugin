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
namespace MuckiFacilityPlugin\Tests\Services\Mirror;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Mirror\LocalRepositoryScanner;

class LocalRepositoryScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mucki_scan_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function put(string $rel, string $content = 'x'): void
    {
        $full = $this->dir . '/' . $rel;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $content);
    }

    public function testScanSkipsLocks(): void
    {
        $this->put('config', '12345');
        $this->put('keys/k', 'abc');
        $this->put('data/aa/pack', 'abcdef');
        $this->put('locks/l1', 'lock');
        $result = (new LocalRepositoryScanner())->scan($this->dir);
        ksort($result);
        self::assertSame(['config' => 5, 'data/aa/pack' => 6, 'keys/k' => 3], $result);
    }

    public function testSymlinkedFileInsideRepositoryThrows(): void
    {
        $this->put('config');
        $this->put('data/aa/real', 'x');
        symlink($this->dir . '/data/aa/real', $this->dir . '/data/aa/link');
        $this->expectException(\RuntimeException::class);
        (new LocalRepositoryScanner())->scan($this->dir);
    }

    public function testSymlinkedDataDirThrows(): void
    {
        $this->put('config');
        $this->put('keys/k');
        $target = $this->dir . '_target';
        mkdir($target);
        file_put_contents($target . '/f', 'x');
        try {
            symlink($target, $this->dir . '/data');
            $this->expectException(\RuntimeException::class);
            (new LocalRepositoryScanner())->scan($this->dir);
        } finally {
            unlink($target . '/f');
            rmdir($target);
        }
    }

    public function testSymlinkUnderLocksIsIgnored(): void
    {
        $this->put('config');
        $this->put('locks/real');
        symlink($this->dir . '/config', $this->dir . '/locks/link');
        self::assertSame(['config' => 1], (new LocalRepositoryScanner())->scan($this->dir));
    }

    public function testSymlinkedKeysDirIsRejected(): void
    {
        $this->put('config');
        $target = $this->dir . '_keys';
        mkdir($target);
        file_put_contents($target . '/k', 'x');
        try {
            symlink($target, $this->dir . '/keys');
            self::assertFalse((new LocalRepositoryScanner())->isResticRepository($this->dir));
        } finally {
            unlink($target . '/k');
            rmdir($target);
        }
    }

    public function testEmptyKeysDirIsNotARepository(): void
    {
        $this->put('config');
        mkdir($this->dir . '/keys');
        self::assertFalse((new LocalRepositoryScanner())->isResticRepository($this->dir));
    }

    public function testMissingConfigIsNotARepository(): void
    {
        $this->put('keys/k');
        self::assertFalse((new LocalRepositoryScanner())->isResticRepository($this->dir));
        self::assertFalse((new LocalRepositoryScanner())->isResticRepository($this->dir . '/nope'));
    }

    public function testRepositoryWithConfigAndKeyIsRecognized(): void
    {
        $this->put('config');
        $this->put('keys/k');
        self::assertTrue((new LocalRepositoryScanner())->isResticRepository($this->dir));
    }

    public function testLockFileIsDetected(): void
    {
        $scanner = new LocalRepositoryScanner();
        self::assertFalse($scanner->hasActiveLocks($this->dir));
        mkdir($this->dir . '/locks');
        self::assertFalse($scanner->hasActiveLocks($this->dir));
        $this->put('locks/abc');
        self::assertTrue($scanner->hasActiveLocks($this->dir));
    }

    public function testUnreadableLocksDirCountsAsLocked(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('chmod 000 does not block root; cannot simulate an unreadable locks/ directory.');
        }
        mkdir($this->dir . '/locks');
        chmod($this->dir . '/locks', 0000);
        try {
            self::assertTrue((new LocalRepositoryScanner())->hasActiveLocks($this->dir));
        } finally {
            chmod($this->dir . '/locks', 0755);
        }
    }
}
