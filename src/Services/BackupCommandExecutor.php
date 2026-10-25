<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 */
namespace MuckiFacilityPlugin\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use MuckiFacilityPlugin\Core\BackupCommandTypes;
use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

class BackupCommandExecutor
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KernelInterface $kernel
    )
    {}

    public function execute(string $type, string $command, bool $failOnError): void
    {
        if($type === BackupCommandTypes::NONE->value || trim($command) === '') {
            return;
        }

        try {
            $process = $this->createProcess($type, trim($command));
            $process->setTimeout(null);
            $process->run();

            if(!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->logger->info(
                'Backup command completed successfully',
                array_merge(PluginDefaults::DEFAULT_LOGGER_CONFIG, ['type' => $type, 'command' => $command])
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Backup command failed: '.$exception->getMessage(),
                array_merge(PluginDefaults::DEFAULT_LOGGER_CONFIG, ['type' => $type, 'command' => $command])
            );

            if($failOnError) {
                throw $exception;
            }
        }
    }

    private function createProcess(string $type, string $command): Process
    {
        return match ($type) {
            BackupCommandTypes::BATCH->value => $this->createBatchProcess($command),
            BackupCommandTypes::PHP->value => $this->createConsoleProcess($command),
            default => throw new \InvalidArgumentException('Unsupported backup command type: '.$type),
        };
    }

    private function createBatchProcess(string $path): Process
    {
        if(!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Backup batch file is not readable: '.$path);
        }
        if(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'sh') {
            throw new \InvalidArgumentException('Backup batch file must use the .sh extension');
        }

        return new Process(['/bin/bash', $path], $this->kernel->getProjectDir());
    }

    private function createConsoleProcess(string $command): Process
    {
        if(preg_match('/^[a-zA-Z0-9:_-]+$/', $command) !== 1) {
            throw new \InvalidArgumentException('Invalid Shopware console command name: '.$command);
        }

        return new Process([
            PHP_BINARY,
            $this->kernel->getProjectDir().'/bin/console',
            $command,
            '--no-interaction',
        ], $this->kernel->getProjectDir());
    }
}
