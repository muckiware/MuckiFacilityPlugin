<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

#[Route(defaults: ['_routeScope' => ['api']])]
class InitBackupRepositoryController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected BackupRepositoryService $backupRepositoryService
    )
    {}

    /**
     * @throws WriteException|\Exception
     */
    #[Route(
        path: '/api/_action/muwa/backup/repository/init',
        name: 'api.action.muwa.backup.repository.init',
        methods: ['POST']
    )]
    public function initRepository(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        if(!$this->checkInputPaths($requestDataBag)) {
            throw new \Exception('Repository path and restore path must be different');
        }

        if(!$this->checkPassword($requestDataBag)) {
            throw new \Exception('Passwords does not match');
        }

        try {
            $initResult = $this->backupRepositoryService->initRepository(
                $this->createRepositoryInitInputs($requestDataBag)
            );
        } catch (\Exception $e) {
            throw new \Exception('Backup repository not found. Message: '.$e->getMessage());
        }

        return new JsonResponse(array(
            'success' => true,
            'message' => 'Backup repository initialized',
            'data' => $initResult
        ));
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

    public function createRepositoryInitInputs(RequestDataBag $requestDataBag): BackupRepositorySettings
    {
        $repositoryInitInputs = new BackupRepositorySettings();
        if($requestDataBag->has('active')) {
            $repositoryInitInputs->setActive($requestDataBag->get('active'));
        }
        if($requestDataBag->has('name')) {
            $repositoryInitInputs->setName($requestDataBag->get('name'));
        }
        if ($requestDataBag->has('forgetDaily')) {
            $repositoryInitInputs->setForgetDaily($requestDataBag->get('forgetDaily'));
        }
        if ($requestDataBag->has('forgetWeekly')) {
            $repositoryInitInputs->setForgetWeekly($requestDataBag->get('forgetWeekly'));
        }
        if ($requestDataBag->has('forgetMonthly')) {
            $repositoryInitInputs->setForgetMonthly($requestDataBag->get('forgetMonthly'));
        }
        if ($requestDataBag->has('forgetYearly')) {
            $repositoryInitInputs->setForgetYearly($requestDataBag->get('forgetYearly'));
        }
        if ($requestDataBag->has('type')) {
            $repositoryInitInputs->setBackupType($requestDataBag->get('type'));
        }
        if ($requestDataBag->has('repositoryPath')) {
            $repositoryInitInputs->setRepositoryPath($requestDataBag->get('repositoryPath'));
        }
        if ($requestDataBag->has('repositoryPassword')) {
            $repositoryInitInputs->setRepositoryPassword($requestDataBag->get('repositoryPassword'));
        }
        if ($requestDataBag->has('restorePath')) {
            $repositoryInitInputs->setRestorePath($requestDataBag->get('restorePath'));
        }
        return $repositoryInitInputs;
    }
}
