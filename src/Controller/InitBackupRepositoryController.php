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

#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('init-backup-repository')]
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
        $backupRepositoryId = $requestDataBag->get('id');
        if(Uuid::isValid($backupRepositoryId) === false) {
            throw new \Exception('Invalid backup repository id');
        }

        $initResult = $this->backupRepositoryService->initRepository($backupRepositoryId);
        return new JsonResponse();
    }
}
