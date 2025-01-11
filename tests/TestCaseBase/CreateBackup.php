<?php

namespace MuckiFacilityPlugin\tests\TestCaseBase;

use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;

class CreateBackup
{
    static public function getCreateBackupEntity(
        string $backupType,
        string $backupRepositoryId=null
    ): BackupRepositorySettings
    {
        $createBackupEntity = new BackupRepositorySettings();
        $createBackupEntity->setBackupPaths(TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATHS);
        $createBackupEntity->setBackupType($backupType);
        if($backupRepositoryId) {
            $createBackupEntity->setBackupRepositoryId($backupRepositoryId);
        } else {
            $createBackupEntity->setBackupRepositoryId(Uuid::randomHex());
        }
        $createBackupEntity->setRepositoryPassword(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD);
        $createBackupEntity->setRepositoryPath(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH);
        $createBackupEntity->setRestorePath(TestCaseBaseDefaults::DEFAULT_TEST_RESTORE_PATH);

        return $createBackupEntity;
    }
    static public function getBackupRepositoryEntity(
        string $backupType,
        string $backupRepositoryId=null
    ): BackupRepositoryEntity
    {
        $backupRepositoryEntity = new BackupRepositoryEntity();

        if($backupRepositoryId) {
            $backupRepositoryEntity->setId($backupRepositoryId);
        } else {
            $backupRepositoryEntity->setId(Uuid::randomHex());
        }
        $backupRepositoryEntity->setType($backupType);
        $backupRepositoryEntity->setRepositoryPath(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH);
        $backupRepositoryEntity->setRepositoryPassword(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD);
        $backupRepositoryEntity->setBackupPaths(TestCaseBaseDefaults::DEFAULT_TEST_BACKUP_PATHS);

        return $backupRepositoryEntity;
    }

}