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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;

use MuckiFacilityPlugin\Entity\MirrorResult;
use MuckiFacilityPlugin\Services\RepositoryMirror;

#[AsCommand(
    name: 'muckiware:repository:upload',
    description: 'Mirror a backup repository to its configured S3 target'
)]
class RepositoryUpload extends Commands
{
    protected ?ContainerInterface $container = null;

    public function __construct(
        protected LoggerInterface $logger,
        protected RepositoryMirror $repositoryMirror,
        protected LockFactory $lockFactory
    ) {
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
        $this->setDescription('Mirror a backup repository to its configured S3 target');
        $this->addArgument('backupRepositoryId', InputArgument::REQUIRED, 'Backup repository id');
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Upload even if the last repository check is not healthy'
        );
        parent::configure();
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $backupRepositoryId = $this->checkInputForBackupRepositoryId($input);

        $lock = $this->lockFactory->createLock('mucki-facility.backup', null);
        if (!$lock->acquire(false)) {

            $output->writeln('A backup is currently running, please try again later');
            return 1;
        }

        try {
            $result = $this->repositoryMirror->mirror($backupRepositoryId, 'cli', null, (bool) $input->getOption('force'));
        } finally {
            $lock->release();
        }

        if ($result === null) {

            $output->writeln('no mirror configured, mirror already running, or unexpected error (see log)');
            return 1;
        }

        $this->printResult($output, $result);

        return $result->getStatus() === MirrorResult::STATUS_SUCCEEDED ? 0 : 1;
    }

    protected function printResult(OutputInterface $output, MirrorResult $result): void
    {
        $output->writeln('Status: ' . $result->getStatus());
        $output->writeln('Files uploaded: ' . $result->getFilesUploaded());
        $output->writeln('Files deleted: ' . $result->getFilesDeleted());
        $output->writeln('Files unchanged: ' . $result->getFilesUnchanged());
        $output->writeln('Bytes uploaded: ' . $result->getBytesUploaded());
        if ($result->getMessage() !== null && $result->getMessage() !== '') {
            $output->writeln('Message: ' . $result->getMessage());
        }
    }
}
