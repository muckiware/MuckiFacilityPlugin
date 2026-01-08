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
namespace MuckiFacilityPlugin\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;

use MuckiFacilityPlugin\Services\ManageRepository as ManageService;

#[Route(defaults: ['_routeScope' => ['api']])]
class ManageController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ManageService $manageService,
    )
    {}

    /**
     * @throws ExceptionInterface
     */
    #[Route(
        path: '/api/_action/muwa/manage/snapshots',
        name: 'api.action.muwa.manage.snapshots',
        methods: ['POST']
    )]
    public function getSnapshots(RequestDataBag $requestDataBag, Context $context): Response
    {
        $snapshots = array();
        $backupRepositoryId = $requestDataBag->get('id');
        if(Uuid::isValid($backupRepositoryId)) {
            $snapshots = json_decode($this->manageService->getSnapshots($backupRepositoryId));
        }

        return new JsonResponse($snapshots);
    }

    #[Route(
        path: '/api/_action/muwa/remove/snapshots',
        name: 'api.action.muwa.remove.snapshots',
        methods: ['POST']
    )]
    public function removeSnapshots(RequestDataBag $requestDataBag, Context $context): Response
    {
        $removedSnapshot = $this->removeSnapshotsByIds(
            $this->getSnapshotIds($requestDataBag),
            $requestDataBag->get('backupRepositoryId')
        );

        return new JsonResponse($removedSnapshot);
    }

    protected function removeSnapshotsByIds(array $snapshotsByIds, string $backupRepositoryId): array
    {
        $removedSnapshot = array();
        foreach ($snapshotsByIds as $selectedSnapshot) {

            $this->manageService->removeSnapshotById($backupRepositoryId, $selectedSnapshot['snapshotId']);
            $this->manageService->saveSnapshots($backupRepositoryId);
            $this->manageService->cleanupRepository($backupRepositoryId);
            $removedSnapshot[] = $selectedSnapshot['snapshotId'];
        }

        return $removedSnapshot;
    }

    protected function getSnapshotIds(RequestDataBag $requestDataBag): array
    {
        $backupRepositoryId = $requestDataBag->get('backupRepositoryId');
        if(is_string($backupRepositoryId) && Uuid::isValid($backupRepositoryId)) {
            return $requestDataBag->get('selectedSnapshots')->all();
        }

        return [];
    }
}
