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
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use MuckiRestic\Entity\Result\ResultEntity;
use MuckiRestic\Library\Backup;
use MuckiRestic\Library\Manage;
use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiFacilityPlugin\Services\Content\BackupFileSnapshotsRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepository;
use MuckiFacilityPlugin\Services\Content\BackupRepositoryChecks;
use MuckiFacilityPlugin\Services\Helper as PluginHelper;
use MuckiFacilityPlugin\Services\ManageRepository;
use MuckiFacilityPlugin\Services\SettingsInterface;

class ManageRepositoryTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private BackupRepository&MockObject $backupRepository;
    private BackupRepositoryChecks&MockObject $checks;
    private PluginHelper&MockObject $helper;
    private SettingsInterface&MockObject $settings;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->backupRepository = $this->createMock(BackupRepository::class);
        $this->checks = $this->createMock(BackupRepositoryChecks::class);
        $this->helper = $this->createMock(PluginHelper::class);
        $this->settings = $this->createMock(SettingsInterface::class);
        $this->settings->method('hasOwnResticBinaryPath')->willReturn(false);
    }

    private function service(?Manage $manage, ?Backup $backup = null): ManageRepository
    {
        return new class(
            $this->logger,
            $this->backupRepository,
            $this->createMock(BackupFileSnapshotsRepository::class),
            $this->checks,
            $this->settings,
            $this->helper,
            $this->createMock(EventDispatcherInterface::class),
            $manage,
            $backup
        ) extends ManageRepository {
            public function __construct(
                LoggerInterface $logger,
                BackupRepository $backupRepository,
                BackupFileSnapshotsRepository $snapshots,
                BackupRepositoryChecks $checks,
                SettingsInterface $settings,
                PluginHelper $helper,
                EventDispatcherInterface $dispatcher,
                private readonly ?Manage $manage,
                private readonly ?Backup $backup
            ) {
                parent::__construct($logger, $backupRepository, $snapshots, $checks, $settings, $helper, $dispatcher);
            }

            protected function createManageClient(): Manage
            {
                return $this->manage ?? throw new \LogicException('no manage client expected');
            }

            protected function createBackupClient(): Backup
            {
                return $this->backup ?? throw new \LogicException('no backup client expected');
            }
        };
    }

    private function resultWith(string $output): ResultEntity
    {
        $result = new ResultEntity();
        $result->setOutput($output);

        return $result;
    }

    public function testReadSnapshotsUsesNoLockAndDecodes(): void
    {
        $manage = $this->createMock(Manage::class);
        $manage->expects($this->once())->method('setNoLock')->with(true);
        $manage->expects($this->once())->method('setJsonOutput')->with(true);
        $manage->expects($this->once())->method('setRepositoryPath')->with('/srv/repo');
        $manage->expects($this->once())->method('setRepositoryPassword')->with('pw');
        $manage->method('getSnapshots')->willReturn($this->resultWith('[{"id":"a"}]'));

        $this->assertSame(
            [['id' => 'a']],
            $this->service($manage)->readSnapshots('/srv/repo', 'pw')
        );
    }

    public function testReadSnapshotsRethrowsProcessFailure(): void
    {
        $manage = $this->createMock(Manage::class);
        $manage->method('getSnapshots')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->never())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $this->service($manage)->readSnapshots('/srv/repo', 'pw');
    }

    #[DataProvider('nonListProvider')]
    public function testReadSnapshotsRejectsNonList(string $output): void
    {
        $manage = $this->createMock(Manage::class);
        $manage->method('getSnapshots')->willReturn($this->resultWith($output));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('restic snapshots returned no valid JSON list');
        $this->service($manage)->readSnapshots('/srv/repo', 'secret-pw');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonListProvider(): array
    {
        return ['null' => ['null'], 'object' => ['{"a":1}'], 'garbage' => ['not json']];
    }

    public function testCheckRepositoryStructureSkipsReadDataAndSavesStatus(): void
    {
        $entity = new BackupRepositoryEntity();
        $entity->setRepositoryPath('/srv/repo');
        $entity->setRepositoryPassword('pw');
        $this->backupRepository->method('getBackupRepositoryById')->with('id1')->willReturn($entity);

        $backup = $this->createMock(Backup::class);
        $backup->expects($this->once())->method('setCheckReadData')->with(false);
        $backup->expects($this->once())->method('setRepositoryPath')->with('/srv/repo');
        $backup->expects($this->once())->method('setRepositoryPassword')->with('pw');
        $backup->method('checkBackup')->willReturn($this->resultWith('check output'));

        $this->helper->method('getCheckResults')->with('check output')
            ->willReturn(['using temporary cache', 'no errors were found']);
        $this->checks->expects($this->once())->method('saveNewCheck')->with('id1', 'no errors were found');

        $this->assertSame('no errors were found', $this->service(null, $backup)->checkRepositoryStructure('id1'));
    }

    public function testCheckRepositoryStructureThrowsForUnknownRepository(): void
    {
        $this->backupRepository->method('getBackupRepositoryById')->willReturn(null);
        $this->checks->expects($this->never())->method('saveNewCheck');

        $this->expectException(\RuntimeException::class);
        $this->service(null, null)->checkRepositoryStructure('missing');
    }
}
