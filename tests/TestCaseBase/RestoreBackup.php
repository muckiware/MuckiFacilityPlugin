<?php

namespace MuckiFacilityPlugin\tests\TestCaseBase;

use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Entity\BackupPathEntity;

class RestoreBackup
{
    public static function getRestoreBackupEntity(string $snapshotId): BackupRepositorySettings
    {
        $restoreBackupEntity = new BackupRepositorySettings();
        $restoreBackupEntity->setRepositoryPassword(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD);
        $restoreBackupEntity->setRepositoryPath(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH
        );
        $restoreBackupEntity->setResticPath(TestCaseBaseDefaults::getResticPath());
        $restoreBackupEntity->setRestorePath(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_RESTORE_PATH
        );
        $restoreBackupEntity->setSnapshotId($snapshotId);

        return $restoreBackupEntity;
    }
}
