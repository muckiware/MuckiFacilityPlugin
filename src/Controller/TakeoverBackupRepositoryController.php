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

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Exception\RepositoryTakeoverException;
use MuckiFacilityPlugin\Services\BackupPathSuggestions;
use MuckiFacilityPlugin\Services\RepositoryTakeover;

#[Route(defaults: ['_routeScope' => ['api']])]
class TakeoverBackupRepositoryController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected RepositoryTakeover $repositoryTakeover,
        protected BackupPathSuggestions $backupPathSuggestions
    )
    {}

    /**
     * Uebernimmt ein bestehendes restic-Repository. Das Passwort wird unveraendert (ungetrimmt)
     * durchgereicht und taucht in keiner Antwort auf. Andere Exceptions als
     * RepositoryTakeoverException werden bewusst nicht abgefangen.
     */
    #[Route(
        path: '/api/_action/muwa/backup/repository/takeover',
        name: 'api.action.muwa.backup.repository.takeover',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:create']],
        methods: ['POST']
    )]
    public function takeover(RequestDataBag $data, Context $context): JsonResponse
    {
        $id = $this->readString($data, 'id');

        try {
            $snapshotsCount = $this->repositoryTakeover->takeover(
                $id,
                $this->readString($data, 'repositoryPath'),
                $this->readString($data, 'repositoryPassword')
            );
        } catch (RepositoryTakeoverException $exception) {
            return new JsonResponse(
                ['errors' => [[
                    'code' => $exception->getErrorCode(),
                    'detail' => $exception->getMessage(),
                    'meta' => $exception->getMeta(),
                ]]],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['success' => true, 'id' => $id, 'snapshotsCount' => $snapshotsCount]);
    }

    #[Route(
        path: '/api/_action/muwa/backup/repository/path-suggestions',
        name: 'api.action.muwa.backup.repository.path_suggestions',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function pathSuggestions(RequestDataBag $data, Context $context): JsonResponse
    {
        $id = $this->readString($data, 'id');
        if (!Uuid::isValid($id)) {
            return new JsonResponse(
                ['errors' => [['code' => 'MUWA_INVALID_ID', 'detail' => 'Missing or invalid backup repository id']]],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse($this->backupPathSuggestions->collect($id));
    }

    protected function readString(RequestDataBag $data, string $key): string
    {
        $value = $data->get($key);

        return is_string($value) ? $value : '';
    }
}
