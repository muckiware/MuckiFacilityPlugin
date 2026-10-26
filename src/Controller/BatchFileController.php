<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 */
namespace MuckiFacilityPlugin\Controller;

use MuckiFacilityPlugin\Services\BatchFileUploader;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class BatchFileController extends AbstractController
{
    public function __construct(private readonly BatchFileUploader $batchFileUploader)
    {
    }

    #[Route(
        path: '/api/_action/muwa/filesystem/batch-file',
        name: 'api.action.muwa.filesystem.batch_file',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function upload(Request $request, Context $context): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->badRequest('No batch file was uploaded');
        }

        try {
            return new JsonResponse(['path' => $this->batchFileUploader->upload($file)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequest($exception->getMessage());
        }
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => 'MUWA_INVALID_BATCH_FILE', 'detail' => $message]]],
            Response::HTTP_BAD_REQUEST
        );
    }
}
