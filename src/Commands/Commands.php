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
}
