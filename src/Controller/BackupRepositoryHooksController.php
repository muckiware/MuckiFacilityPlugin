<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2026 by Muckiware
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

/**
 * Pre-/post-backup commands run on the backup worker with shell access (batch
 * type: /bin/bash on an uploaded or provisioned script). Their configuration
 * is therefore deliberately NOT writable through the generic DAL route — the
 * fields are WriteProtected (system scope) and this action route is the only
 * write path, protected by its own high-risk privilege (security finding S-02).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class BackupRepositoryHooksController extends AbstractController
{
    public function __construct(protected BackupRepositoryService $backupRepositoryService)
    {
    }

    #[Route(
        path: '/api/_action/muwa/backup/{backupRepositoryId}/hooks',
        name: 'api.action.muwa.backup.repository.hooks',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:execute_commands']],
        methods: ['PUT']
    )]
    public function update(string $backupRepositoryId, RequestDataBag $data, Context $context): Response
    {
        if (!Uuid::isValid($backupRepositoryId)) {
            return $this->badRequest('MUWA_INVALID_ID', 'Invalid backup repository id');
        }

        try {
            $payload = BackupRepositoryService::assertValidHookConfiguration($data->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequest('MUWA_INVALID_HOOK_CONFIGURATION', $exception->getMessage());
        }

        if (!$this->backupRepositoryService->updateBackupRepositoryHooks($backupRepositoryId, $payload)) {
            throw new NotFoundHttpException('Backup repository not found');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function badRequest(string $code, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => $code, 'detail' => $detail]]],
            JsonResponse::HTTP_BAD_REQUEST
        );
    }
}
