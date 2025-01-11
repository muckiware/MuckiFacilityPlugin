<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Helper;
use MuckiFacilityPlugin\Core\BackupTypes;

class HelperTest extends TestCase
{
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
