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
namespace MuckiFacilityPlugin\Services;

use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class CliOutput
{
    protected OutputInterface $output;

    protected bool $isCli = false;
    final const PROGRESS_BAR_OFFSET = 0;

    protected string $progressMessage = '';

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function isCli(): bool
    {
        return $this->isCli;
    }

    public function setIsCli(bool $isCli): void
    {
        $this->isCli = $isCli;
    }

    public function getProgressMessage(): string
    {
        return $this->progressMessage;
    }

    public function setProgressMessage(string $progressMessage): void
    {
        $this->progressMessage = $progressMessage;
    }

    public function prepareProgressBar(
        Progress $progress,
        int $totalCounter
    ): ProgressBar
    {
        if(!$progress->getTotal()) {
            $progressTotal = 0;
        } else {
            $progressTotal = $progress->getTotal();
        }
        $progressBar = new ProgressBar($this->output, $totalCounter);
        $progressBar->setMaxSteps($progressTotal);
        $progressBar->setFormat('[%bar%] %current%/%max% '.$this->progressMessage."\n");
        $progressBar->start();

        return $progressBar;
    }

    public function prepareProgress(int $totalCounter): Progress
    {
        $progress = new Progress(Uuid::randomHex(), Progress::STATE_PROGRESS, self::PROGRESS_BAR_OFFSET);
        $progress->setTotal($totalCounter);
        $progress->setOffset(self::PROGRESS_BAR_OFFSET);

        return $progress;
    }

    public function printCliOutput(string $message = ''): void
    {
        if($message !== '') {
            $this->output?->writeln($message);
        }
    }

    public function printCliOutputNewline(?string $message = ''): void
    {
        if($message && $message !== '') {
            $this->output?->write($message, true);
        }
    }
}

