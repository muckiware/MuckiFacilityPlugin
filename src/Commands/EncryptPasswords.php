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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

#[AsCommand(
    name: 'muckiware:backup:encrypt-passwords',
    description: 'Encrypt repository passwords that are still stored as plain text'
)]
class EncryptPasswords extends Commands
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
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would change');
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');

        $plainRepositories = $this->backupRepositoryService->getRepositoryNamesByPasswordSource(PasswordSource::PLAIN);

        if ($plainRepositories === []) {
            $symfonyStyle->success('No repository with a plain text password found.');
            return self::SUCCESS;
        }

        $symfonyStyle->title(sprintf('Found %d repository/repositories to encrypt', count($plainRepositories)));

        $failed = 0;
        foreach ($plainRepositories as $repositoryId => $repositoryName) {

            if ($isDryRun) {
                $symfonyStyle->writeln(sprintf(' - %s (%s) would be encrypted', $repositoryName, $repositoryId));
                continue;
            }

            try {
                $this->encryptRepositoryPassword($repositoryId);
                $symfonyStyle->writeln(sprintf(' - %s (%s) encrypted', $repositoryName, $repositoryId));
            } catch (\Exception $e) {
                ++$failed;
                $symfonyStyle->error(sprintf('%s (%s): %s', $repositoryName, $repositoryId, $e->getMessage()));
                $this->logger->error($e->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            }
        }

        if ($isDryRun) {
            $symfonyStyle->note('Dry run, nothing was written.');
            return self::SUCCESS;
        }

        if ($failed > 0) {
            $symfonyStyle->error(sprintf('%d repository/repositories could not be encrypted.', $failed));
            return self::FAILURE;
        }

        $symfonyStyle->success('All plain text repository passwords are encrypted now.');

        return self::SUCCESS;
    }

    /**
     * @throws \Exception
     */
    protected function encryptRepositoryPassword(string $backupRepositoryId): void
    {
        $backupRepository = $this->backupRepositoryService->getBackupRepositoryById($backupRepositoryId);
        if ($backupRepository === null) {
            throw new \RuntimeException('Backup repository disappeared while processing');
        }

        $storedPassword = $this->backupRepositoryService->prepareStoredPassword(
            $backupRepository->getRepositoryPassword(),
            PasswordSource::ENCRYPTED
        );

        $this->backupRepositoryService->updateRepositoryPassword(
            $backupRepositoryId,
            $storedPassword,
            PasswordSource::ENCRYPTED
        );
    }
}
