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
namespace MuckiFacilityPlugin\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

#[AsCommand(
    name: 'muckiware:backup:password-source',
    description: 'Set the password source of a backup repository (encrypted, env or file)'
)]
class PasswordSourceCommand extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected BackupRepositoryService $backupRepositoryService
    )
    {
        parent::__construct();
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /**
     * @internal
     */
    public function configure(): void
    {
        $this->addArgument('backupRepositoryId', InputArgument::REQUIRED, 'Backup repository id');
        $this->addArgument(
            'source',
            InputArgument::REQUIRED,
            'One of: encrypted, env, file'
        );
        $this->addArgument(
            'value',
            InputArgument::REQUIRED,
            'The password (encrypted), the environment variable name (env) or the file path (file)'
        );
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $backupRepositoryId = $this->checkInputForBackupRepositoryId($input);

        $passwordSource = PasswordSource::tryFrom((string) $input->getArgument('source'));
        if ($passwordSource === null || $passwordSource === PasswordSource::PLAIN) {
            $symfonyStyle->error('Invalid source. Allowed values: encrypted, env, file');
            return self::FAILURE;
        }

        try {

            $storedPassword = $this->backupRepositoryService->prepareStoredPassword(
                (string) $input->getArgument('value'),
                $passwordSource
            );

            $this->backupRepositoryService->updateRepositoryPassword(
                $backupRepositoryId,
                $storedPassword,
                $passwordSource
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $symfonyStyle->error($e->getMessage());
            return self::FAILURE;
        }

        $symfonyStyle->success(
            sprintf('Password source of %s is now "%s".', $backupRepositoryId, $passwordSource->value)
        );

        if ($passwordSource->storesSecretInDatabase()) {
            $symfonyStyle->note(
                'The secret is stored in the database. It can only be read back with the same '
                . 'MUWA_FACILITY_SECRET — keep that value with your disaster recovery notes.'
            );
        }

        return self::SUCCESS;
    }
}
