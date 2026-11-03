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
namespace MuckiFacilityPlugin\Backup\Database;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;

/**
 * Packs a plain sql dump into a real tar.gz archive.
 *
 * A gzip pipe (mysqldump | gzip) only produces a gzip stream without tar container, which can not be
 * unpacked with "tar -xzf". tar itself streams the file, so large dumps are not loaded into memory.
 */
class DumpArchiver
{
    public const ARCHIVE_EXTENSION = '.tar.gz';

    public function __construct(
        protected LoggerInterface $logger
    ) {}

    public function getArchiveFileName(string $dumpFile): string
    {
        return $dumpFile.self::ARCHIVE_EXTENSION;
    }

    /**
     * Returns false if the archive could not be created, the plain dump is kept in that case so the
     * backup still contains the database.
     */
    public function archive(string $dumpFile): bool
    {
        if(!is_file($dumpFile)) {
            $this->logger->error('Database dump file for archive not found: '.$dumpFile, PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return false;
        }

        $archiveFile = $this->getArchiveFileName($dumpFile);
        $process = $this->createProcess($archiveFile, $dumpFile);
        $process->setTimeout(null);
        $process->run();

        if(!$process->isSuccessful()) {
            $this->logger->error(
                'Database dump could not be archived, plain dump is kept: '.$process->getErrorOutput(),
                PluginDefaults::DEFAULT_LOGGER_CONFIG
            );
            if(is_file($archiveFile)) {
                unlink($archiveFile);
            }
            return false;
        }

        unlink($dumpFile);

        return true;
    }

    protected function createProcess(string $archiveFile, string $dumpFile): Process
    {
        // -C keeps the archive free of the absolute dump path, it only contains the file name
        return new Process(['tar', '-czf', $archiveFile, '-C', dirname($dumpFile), basename($dumpFile)]);
    }
}
