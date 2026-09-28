<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Core\BackupTypes;

class HelperTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $tempPath) {
            if (is_file($tempPath)) {
                unlink($tempPath);
                continue;
            }
            self::deleteDirectory($tempPath);
            if (is_dir($tempPath)) {
                rmdir($tempPath);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    public function testCheckHelperFunction(): void
    {
        $helperClass = new Helper();
        $hashData = $helperClass->getHashData('abc123');
        static::assertIsString($hashData, 'hash data method with string result as md5 hash');

        $hashData = $helperClass->getHashData(['abc123']);
        static::assertIsString($hashData, 'hash data method with string result as md5 hash');
    }

    public function testCheckValidEmailFunction(): void
    {
        $helperClass = new Helper();
        $isValidEmailResults = $helperClass->isValidEmail('test@test.com');
        static::assertIsBool($isValidEmailResults, 'isValidEmailResult is boolean');
        static::assertTrue($isValidEmailResults, 'isValidEmailResult should be true. E-Mail is valid');

        $isValidEmailResultsNoValid = $helperClass->isValidEmail('test_test.com');
        static::assertFalse($isValidEmailResultsNoValid, 'isValidEmailResult should be false. E-Mail not valid');
    }

    public function testEnsureDirectoryExistsCreatesMissingDirectories(): void
    {
        $helperClass = new Helper();
        $path = $this->createTempPath().'/nested/dump';

        static::assertTrue(
            $helperClass->ensureDirectoryExists($path),
            'A missing directory should be reported as existing afterwards'
        );
        static::assertDirectoryExists($path, 'Missing directories should be created recursively');
    }

    public function testEnsureDirectoryExistsAcceptsAnExistingDirectory(): void
    {
        $helperClass = new Helper();
        $path = $this->createTempPath();
        self::createDirectory($path);

        static::assertTrue(
            $helperClass->ensureDirectoryExists($path),
            'An already existing directory should be reported as existing'
        );
    }

    public function testEnsureDirectoryExistsReturnsFalseWhenTheDirectoryCannotBeCreated(): void
    {
        $helperClass = new Helper();
        $blockingFile = $this->createTempPath();
        file_put_contents($blockingFile, 'no directory');

        set_error_handler(static fn (): bool => true);
        try {
            $result = $helperClass->ensureDirectoryExists($blockingFile.'/dump');
        } finally {
            restore_error_handler();
        }

        static::assertFalse(
            $result,
            'A directory which is blocked by an existing file should be reported as not created'
        );
    }

    public function testDeleteDirectoryRemovesNestedFilesAndDirectories(): void
    {
        $helperClass = new Helper();
        $path = $this->createTempPath();
        self::createTextFiles($path.'/nested', ['a', 'b']);

        static::assertTrue($helperClass->deleteDirectory($path), 'A successful deletion should return true');
        static::assertDirectoryDoesNotExist($path, 'The directory and its contents should be gone');
    }

    public function testDeleteDirectoryReturnsTrueWhenTheDirectoryDoesNotExist(): void
    {
        $helperClass = new Helper();

        static::assertTrue(
            $helperClass->deleteDirectory($this->createTempPath()),
            'Nothing to delete should count as success'
        );
    }

    public function testDeleteDirectoryRefusesToDeleteASymlinkDirectory(): void
    {
        // dbDumpPath itself must never be able to point at a symlink: dropping that symlink is
        // easy to undo, but if the plugin followed it and cleared the target instead, an admin
        // could point the dump path at a symlink to the restic repository, or to var/log/etc.,
        // and every backup run would empty it before the deletion could even be noticed.
        $helperClass = new Helper();
        $targetPath = $this->createTempPath();
        self::createTextFiles($targetPath, ['keep-me']);

        // Not tracked via createTempPath(): is_dir()/rmdir() follow symlinks, so letting
        // tearDown() run its own recursive delete on this path would walk into $targetPath
        // instead of just removing the link. unlink() below is the symlink-safe way to remove it.
        $symlinkPath = sys_get_temp_dir().'/muwa-helper-test-'.uniqid('', true);
        static::assertTrue(symlink($targetPath, $symlinkPath), 'Test setup: creating the symlink must succeed');

        try {
            static::assertFalse(
                $helperClass->deleteDirectory($symlinkPath),
                'A symlink as the top-level path must be refused'
            );
            static::assertDirectoryExists($targetPath, 'The symlink target must be untouched');
            static::assertFileExists($targetPath.'/file1.txt', 'Files inside the symlink target must survive');
        } finally {
            unlink($symlinkPath);
        }
    }

    public function testDeleteDirectoryRemovesASymlinkInsideWithoutFollowingIt(): void
    {
        // A symlink found while walking the directory must be removed as a link, never
        // recursed into - otherwise deleting a dump directory could delete an unrelated
        // directory it happens to link to.
        $helperClass = new Helper();
        $externalTarget = $this->createTempPath();
        self::createTextFiles($externalTarget, ['do-not-delete-me']);

        $dumpPath = $this->createTempPath();
        self::createDirectory($dumpPath);
        static::assertTrue(
            symlink($externalTarget, $dumpPath.'/linked-elsewhere'),
            'Test setup: creating the symlink must succeed'
        );

        static::assertTrue($helperClass->deleteDirectory($dumpPath), 'Deletion should succeed');
        static::assertDirectoryDoesNotExist($dumpPath, 'The dump directory itself should be gone');
        static::assertDirectoryExists($externalTarget, 'The symlink target must survive untouched');
        static::assertFileExists(
            $externalTarget.'/file1.txt',
            'Files inside the symlink target must not be deleted'
        );
    }

    private function createTempPath(): string
    {
        $tempPath = sys_get_temp_dir().'/muwa-helper-test-'.uniqid('', true);
        $this->tempPaths[] = $tempPath;

        return $tempPath;
    }

    public static function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        return true;
    }

    public static function createDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0777, true);
        }
        return false;
    }

    public static function createTextFiles(string $directory, array $files): bool
    {
        self::createDirectory($directory);
        foreach ($files as $index => $content) {
            $filePath = $directory.DIRECTORY_SEPARATOR.'file'.($index + 1).'.txt';
            if (file_put_contents($filePath, $content) === false) {
                return false;
            }
        }

        return true;
    }

//    public function testCheckBackupType()
//    {
//        $helperClass = new Helper();
//        $backupTypes = BackupTypes::cases();
//        foreach ($backupTypes as $backupType) {
//
//            $checkBackupTypByInputResult = $helperClass->checkBackupTypByInput($backupType->value);
//            static::assertTrue($checkBackupTypByInputResult, 'Backup type '.$backupType->value.'is not valid');
//        }
//
//        $checkBackupTypByInputResult = $helperClass->checkBackupTypByInput('test');
//        static::assertFalse($checkBackupTypByInputResult, 'Backup type "test" should not valid');
//    }
}
