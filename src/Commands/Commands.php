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
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Core\CleanupTables;

class Commands extends Command
{
    protected function checkInputForBackupRepositoryId(InputInterface $input): string
    {
        $backupRepositoryId = $input->getArgument('backupRepositoryId');
        if(
            $backupRepositoryId &&
            $backupRepositoryId !== '' &&
            Uuid::isValid($backupRepositoryId)
        ) {
            return $backupRepositoryId;
        }

        throw new \InvalidArgumentException('Invalid or missing backup repository id');
    }

    protected function checkInputForBackupType(InputInterface $input): string
    {
        $backupTypeInput = $input->getArgument('backupType');
        if(
            $backupTypeInput &&
            $backupTypeInput !== '' &&
            $this->checkExistingEnumTypes(BackupTypes::cases(), $backupTypeInput)
        ) {
            return $backupTypeInput;
        } else {

            // Default backup type
            return BackupTypes::COMPLETE_DATABASE_SINGLE_FILE->value;
        }
    }

    protected function checkInputForTableCleanupType(InputInterface $input): ?string
    {
        $tableNameInput = $input->getArgument('tableName');
        if(
            $tableNameInput &&
            $tableNameInput !== '' &&
            $this->checkExistingEnumTypes(CleanupTables::cases(), $tableNameInput)
        ) {
            return $tableNameInput;
        }

        return null;
    }

    public function checkExistingEnumTypes(array $checkTypes, string $typeInput): bool
    {
        foreach ($checkTypes as $backupType) {
            if ($backupType->value === $typeInput) {
                return true;
            }
        }

        return false;
    }
}
