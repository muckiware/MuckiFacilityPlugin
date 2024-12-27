<?php

namespace MuckiFacilityPlugin\Backup\Files;

use Psr\Log\LoggerInterface;

use MuckiRestic\Library\Backup;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Services\SettingsInterface as PluginSettings;

class FilesRunner
{
    public function __construct(
        protected LoggerInterface $logger,
        protected PluginSettings $pluginSettings
    ) {}

    public function createBackupData(): void
    {

        $backupClient = Backup::create();
        if($this->pluginSettings->hasOwnResticBinaryPath()) {
            $backupClient->setBinaryPath($this->pluginSettings->getOwnResticBinaryPath());
        }

        $backupClient->setRepositoryPassword('1234');
        $backupClient->setRepositoryPath('./path_to_repository');
        $backupClient->setBackupPath('./path_to_backup_folder');


        $this->logger->debug('Files backup process started', PluginDefaults::DEFAULT_LOGGER_CONFIG);
    }
}
