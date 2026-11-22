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
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;

#[Route(defaults: ['_routeScope' => ['api']])]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        protected BackupRepositoryService $backupRepositoryService
    )
    {}
    #[Route(
        path: '/api/_action/muwa/backup/process',
        name: 'api.action.muwa.backup.process',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:backup']],
        methods: ['POST']
    )]
    public function process(RequestDataBag $data, Context $context): Response
    {
        $backupRepositoryId = (string) $data->get('id');

        if ($this->backupRepositoryService->isBackupRepositoryInactive($backupRepositoryId)) {
            return new JsonResponse(
                ['errors' => [[
                    'code' => 'MUWA_REPOSITORY_INACTIVE',
                    'detail' => 'Backup repository is inactive.',
                ]]],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        // Pre-/post-backup commands execute arbitrary shell code on the worker.
        // Starting a backup of a repository with a configured command therefore
        // requires the dedicated high-risk privilege, not just :backup
        // (security finding S-02).
        if (
            $this->backupRepositoryService->hasBackupCommands($backupRepositoryId)
            && !$context->isAllowed('muwa_backup_repository:execute_commands')
        ) {
            return new JsonResponse(
                ['errors' => [[
                    'code' => 'MUWA_MISSING_PRIVILEGE',
                    'detail' => 'Missing privilege muwa_backup_repository:execute_commands',
                ]]],
                JsonResponse::HTTP_FORBIDDEN
            );
        }

        $this->messageBus->dispatch(new CreateBackupMessage($backupRepositoryId));
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
