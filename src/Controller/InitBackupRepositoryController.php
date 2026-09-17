<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Entity\BackupPathEntity;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

#[Route(defaults: ['_routeScope' => ['api']])]
class InitBackupRepositoryController extends AbstractController
{
    private const DEFAULT_PASSWORD_SOURCE = PasswordSource::ENCRYPTED;

    /**
     * @internal
     */
    public function __construct(
        protected BackupRepositoryService $backupRepositoryService
    )
    {}

    /**
     * Legt das restic-Repository an und speichert erst danach den Datensatz.
     *
     * Reihenfolge ist Absicht:
     * 1. Eingaben pruefen,
     * 2. Passwort in die Speicherform bringen — schlaegt hier MUWA_FACILITY_SECRET fehl,
     *    ist noch nichts passiert,
     * 3. `restic init`,
     * 4. Datensatz schreiben.
     *
     * Dadurch entsteht kein Repository-Eintrag ohne zugehoeriges restic-Repository und kein
     * restic-Repository, dessen Passwort sich nicht ablegen laesst.
     *
     * @throws WriteException|\Exception
     */
    #[Route(
        path: '/api/_action/muwa/backup/repository/init',
        name: 'api.action.muwa.backup.repository.init',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:create']],
        methods: ['POST']
    )]
    public function initRepository(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $this->assertValidInput($requestDataBag);

        $repositoryInitInputs = $this->createRepositoryInitInputs($requestDataBag);

        $storedPassword = $this->backupRepositoryService->prepareStoredPassword(
            $repositoryInitInputs->getRepositoryPassword(),
            self::DEFAULT_PASSWORD_SOURCE
        );

        try {
            $initResult = $this->backupRepositoryService->initRepository($repositoryInitInputs);
        } catch (\Exception $e) {
            throw new \Exception('Backup repository could not be initialized. Message: '.$e->getMessage());
        }

        $this->backupRepositoryService->createBackupRepository(
            $repositoryInitInputs,
            $storedPassword,
            self::DEFAULT_PASSWORD_SOURCE
        );

        return new JsonResponse(array(
            'success' => true,
            'message' => 'Backup repository initialized',
            'data' => $initResult
        ));
    }

    /**
     * @throws \Exception
     */
    protected function assertValidInput(RequestDataBag $requestDataBag): void
    {
        if(!Uuid::isValid((string) $requestDataBag->get('id'))) {
            throw new \Exception('Missing or invalid backup repository id');
        }

        if(trim((string) $requestDataBag->get('name')) === '') {
            throw new \Exception('Name must not be empty');
        }

        if(trim((string) $requestDataBag->get('repositoryPath')) === '') {
            throw new \Exception('Repository path must not be empty');
        }

        if(!$this->checkInputPaths($requestDataBag)) {
            throw new \Exception('Repository path and restore path must be different');
        }

        if(trim((string) $requestDataBag->get('repositoryPassword')) === '') {
            throw new \Exception('Repository password must not be empty');
        }

        if(!$this->checkPassword($requestDataBag)) {
            throw new \Exception('Passwords does not match');
        }
    }

    public function checkPassword(RequestDataBag $requestDataBag): bool
    {
        $password = $requestDataBag->get('repositoryPassword');
        $repeatPassword = $requestDataBag->get('repositoryRepeatPassword');

        if($password !== $repeatPassword) {
            return false;
        }
        return true;
    }

    public function checkInputPaths(RequestDataBag $requestDataBag): bool
    {
        $repositoryPath = $requestDataBag->get('repositoryPath');
        $restorePath = $requestDataBag->get('restorePath');
        if($repositoryPath === $restorePath) {
            return false;
        }
        return true;
    }

    /**
     * Fuellt das DTO vollstaendig.
     *
     * BackupRepositorySettings hat typisierte Properties ohne Defaultwerte — ein spaeterer
     * Getter auf ein nicht gesetztes Feld waere ein \Error. Deshalb werden hier alle Felder
     * belegt, die in den Datensatz wandern.
     */
    public function createRepositoryInitInputs(RequestDataBag $requestDataBag): BackupRepositorySettings
    {
        $repositoryInitInputs = new BackupRepositorySettings();

        $repositoryInitInputs->setBackupRepositoryId((string) $requestDataBag->get('id'));
        $repositoryInitInputs->setActive((bool) $requestDataBag->get('active', false));
        $repositoryInitInputs->setName((string) $requestDataBag->get('name'));
        $repositoryInitInputs->setBackupType((string) $requestDataBag->get('type', ''));
        $repositoryInitInputs->setRepositoryPath((string) $requestDataBag->get('repositoryPath'));
        $repositoryInitInputs->setRepositoryPassword((string) $requestDataBag->get('repositoryPassword'));
        $repositoryInitInputs->setRestorePath((string) $requestDataBag->get('restorePath', ''));
        $repositoryInitInputs->setForgetDaily((int) $requestDataBag->get('forgetDaily', 0));
        $repositoryInitInputs->setForgetWeekly((int) $requestDataBag->get('forgetWeekly', 0));
        $repositoryInitInputs->setForgetMonthly((int) $requestDataBag->get('forgetMonthly', 0));
        $repositoryInitInputs->setForgetYearly((int) $requestDataBag->get('forgetYearly', 0));
        $repositoryInitInputs->setBackupPaths($this->createBackupPaths($requestDataBag));

        $dbDumpPath = trim((string) $requestDataBag->get('dbDumpPath', ''));
        $repositoryInitInputs->setDbDumpPath($dbDumpPath === '' ? null : $dbDumpPath);

        $hostName = trim((string) $requestDataBag->get('hostname', ''));
        if($hostName !== '') {
            $repositoryInitInputs->setHostName($hostName);
        }

        return $repositoryInitInputs;
    }

    /**
     * @return array<int, BackupPathEntity>
     */
    protected function createBackupPaths(RequestDataBag $requestDataBag): array
    {
        $backupPathsInput = $requestDataBag->get('backupPaths');
        if(!$backupPathsInput instanceof RequestDataBag) {
            return [];
        }

        $backupPaths = [];
        /** @var RequestDataBag $backupPath */
        foreach ($backupPathsInput->getIterator() as $backupPath) {

            $backupPathEntity = new BackupPathEntity();
            $backupPathEntity->setId((string) $backupPath->get('id'));
            $backupPathEntity->setBackupPath((string) $backupPath->get('backupPath'));
            $backupPathEntity->setCompress((bool) $backupPath->get('compress', false));
            $backupPathEntity->setPosition((int) $backupPath->get('position', 0));
            $backupPathEntity->setIsDefault((bool) $backupPath->get('isDefault', false));

            $backupPaths[] = $backupPathEntity;
        }

        return $backupPaths;
    }
}
