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
namespace MuckiFacilityPlugin\tests\Backup\Database;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

use MuckiFacilityPlugin\Backup\Database\DumpArchiver;

class DumpArchiverTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/muwa_dump_archiver_'.uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function testArchiveCreatesRealTarGzAndRemovesPlainDump(): void
    {
        $dumpFile = $this->workDir.'/2026-11-03_07-44-36_db.backup.sql';
        file_put_contents($dumpFile, 'CREATE TABLE test (id INT);');

        $archiver = new DumpArchiver($this->createMock(LoggerInterface::class));

        static::assertTrue($archiver->archive($dumpFile));
        static::assertFileDoesNotExist($dumpFile, 'The plain dump should be removed after archiving');

        $archiveFile = $dumpFile.'.tar.gz';
        static::assertSame($archiveFile, $archiver->getArchiveFileName($dumpFile));
        static::assertFileExists($archiveFile);

        // tar -tzf only succeeds on a real tar container, a plain gzip stream would fail here
        $listing = new Process(['tar', '-tzf', $archiveFile]);
        $listing->mustRun();
        static::assertSame(
            '2026-11-03_07-44-36_db.backup.sql',
            trim($listing->getOutput()),
            'The archive should only contain the file name, not the absolute dump path'
        );
    }

    public function testArchiveKeepsPlainDumpWhenFileIsMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');

        $archiver = new DumpArchiver($logger);

        static::assertFalse($archiver->archive($this->workDir.'/missing.backup.sql'));
        static::assertFileDoesNotExist($this->workDir.'/missing.backup.sql.tar.gz');
    }
}
