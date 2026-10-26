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

    public function execute(string $type, string $command, bool $failOnError, string $arguments = ''): void
    {
        if($type === BackupCommandTypes::NONE->value || trim($command) === '') {
            return;
        }

        try {
            $process = $this->createProcess($type, trim($command), $arguments);
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

    private function createProcess(string $type, string $command, string $arguments): Process
    {
        return match ($type) {
            BackupCommandTypes::BATCH->value => $this->createBatchProcess($command, $arguments),
            BackupCommandTypes::PHP->value => $this->createConsoleProcess($command),
            default => throw new \InvalidArgumentException('Unsupported backup command type: '.$type),
        };
    }

    private function createBatchProcess(string $path, string $arguments): Process
    {
        if(!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Backup batch file is not readable: '.$path);
        }
        if(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'sh') {
            throw new \InvalidArgumentException('Backup batch file must use the .sh extension');
        }

        return new Process(array_merge(['/bin/bash', $path], $this->parseArguments($arguments)), $this->kernel->getProjectDir());
    }

    /**
     * Split an argument line without invoking a shell. Single and double quotes group values;
     * backslashes escape the following character outside single quotes.
     *
     * @return array<int, string>
     */
    private function parseArguments(string $arguments): array
    {
        $result = [];
        $current = '';
        $quote = null;
        $escaped = false;
        $tokenStarted = false;

        foreach (str_split(trim($arguments)) as $character) {
            if($escaped) {
                $current .= $character;
                $escaped = false;
                $tokenStarted = true;
                continue;
            }

            if($character === '\\' && $quote !== "'") {
                $escaped = true;
                $tokenStarted = true;
                continue;
            }

            if(($character === '"' || $character === "'") && ($quote === null || $quote === $character)) {
                $quote = $quote === null ? $character : null;
                $tokenStarted = true;
                continue;
            }

            if(ctype_space($character) && $quote === null) {
                if($tokenStarted) {
                    $result[] = $current;
                    $current = '';
                    $tokenStarted = false;
                }
                continue;
            }

            $current .= $character;
            $tokenStarted = true;
        }

        if($quote !== null) {
            throw new \InvalidArgumentException('Unclosed quote in batch arguments');
        }
        if($escaped) {
            throw new \InvalidArgumentException('Trailing escape in batch arguments');
        }
        if($tokenStarted) {
            $result[] = $current;
        }

        return $result;
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
