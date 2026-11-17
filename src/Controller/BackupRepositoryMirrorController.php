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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Exception\InvalidMirrorTargetException;
use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;

/**
 * The S3 mirror deletes remote objects. The mirror target fields are therefore WriteProtected (system scope)
 * and this route is their only write path: otherwise anyone with update rights could point the stored
 * credentials at a foreign bucket.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class BackupRepositoryMirrorController extends AbstractController
{
    public function __construct(
        protected BackupRepositoryService $backupRepositoryService,
        protected MirrorTargetValidator $mirrorTargetValidator
    )
    {}

    #[Route(
        path: '/api/_action/muwa/backup/{backupRepositoryId}/mirror',
        name: 'api.action.muwa.backup.repository.mirror',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['PUT']
    )]
    public function update(string $backupRepositoryId, RequestDataBag $data, Context $context): Response
    {
        if (!Uuid::isValid($backupRepositoryId)) {
            return $this->error('MUWA_INVALID_ID', 'Invalid backup repository id');
        }

        if (!$this->backupRepositoryService->exists($backupRepositoryId)) {
            throw new NotFoundHttpException('Backup repository not found');
        }

        try {
            $payload = $this->mirrorTargetValidator->assertValid($data->all());
        } catch (InvalidMirrorTargetException $exception) {
            return $this->error('MUWA_INVALID_MIRROR_TARGET', $exception->getMessage());
        }

        if ($payload['mirrorS3ConnectionId'] !== null) {
            try {
                $overlap = $this->mirrorTargetValidator->findOverlap(
                    $backupRepositoryId,
                    $payload['mirrorS3ConnectionId'],
                    (string) $payload['mirrorS3Bucket'],
                    (string) $payload['mirrorS3Prefix']
                );
            } catch (InvalidMirrorTargetException $exception) {
                return $this->error('MUWA_INVALID_MIRROR_TARGET', $exception->getMessage());
            }
            if ($overlap !== null) {
                return $this->error(
                    'MUWA_MIRROR_TARGET_OVERLAP',
                    sprintf('The mirror target overlaps with the target of "%s"', $overlap)
                );
            }
        }

        if (!$this->backupRepositoryService->updateMirrorTarget($backupRepositoryId, $payload)) {
            throw new NotFoundHttpException('Backup repository not found');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function error(string $code, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => $code, 'detail' => $detail]]],
            JsonResponse::HTTP_BAD_REQUEST
        );
    }
}
