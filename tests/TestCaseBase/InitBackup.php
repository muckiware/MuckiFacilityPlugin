<?php

namespace MuckiFacilityPlugin\tests\TestCaseBase;

use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\tests\TestCaseBase\Defaults as TestCaseBaseDefaults;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;
use MuckiFacilityPlugin\Core\BackupTypes;

class InitBackup
{
    static public function getRepositoryInit(): BackupRepositorySettings
    {
        $repositoryInitInputs = new BackupRepositorySettings();
        $repositoryInitInputs->setActive(true);
        $repositoryInitInputs->setName('backup TEST');
        $repositoryInitInputs->setForgetDaily(7);
        $repositoryInitInputs->setForgetWeekly(4);
        $repositoryInitInputs->setForgetMonthly(12);
        $repositoryInitInputs->setForgetYearly(35);
        $repositoryInitInputs->setBackupType(BackupTypes::FILES->value);
        $repositoryInitInputs->setRepositoryPath(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PATH
        );
        $repositoryInitInputs->setRepositoryPassword(TestCaseBaseDefaults::DEFAULT_TEST_REPOSITORY_PASSWORD);
        $repositoryInitInputs->setRestorePath(
            TestCaseBaseDefaults::getPluginPath().'/'.TestCaseBaseDefaults::DEFAULT_TEST_RESTORE_PATH
        );
        $repositoryInitInputs->setResticPath(TestCaseBaseDefaults::getResticPath());

        return $repositoryInitInputs;
    }
}
