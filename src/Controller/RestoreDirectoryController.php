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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\RestoreDirectoryBrowser;
use MuckiFacilityPlugin\Services\RestoreDownloadToken;

#[Route(defaults: ['_routeScope' => ['api']])]
class RestoreDirectoryController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected RestoreDirectoryBrowser $restoreDirectoryBrowser,
        protected RestoreDownloadToken $downloadToken,
    )
    {}

    #[Route(
        path: '/api/_action/muwa/restore/directory',
        name: 'api.action.muwa.restore.directory',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:restore']],
        methods: ['POST']
    )]
    public function directory(RequestDataBag $data, Context $context): JsonResponse
    {
        $repositoryId = $this->getString($data, 'backupRepositoryId') ?? '';
        if (!Uuid::isValid($repositoryId)) {
            return $this->invalidPathResponse(new InvalidBrowsePathException('Invalid backup repository id.'));
        }

        try {
            return new JsonResponse($this->restoreDirectoryBrowser->listDirectory(
                $repositoryId,
                $this->getString($data, 'path')
            ));
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }
    }

    #[Route(
        path: '/api/_action/muwa/restore/download/prepare',
        name: 'api.action.muwa.restore.download_prepare',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:restore']],
        methods: ['POST']
    )]
    public function downloadPrepare(RequestDataBag $data, Context $context): JsonResponse
    {
        $repositoryId = $this->getString($data, 'backupRepositoryId') ?? '';
        $path = $this->getString($data, 'path') ?? '';
        if (!Uuid::isValid($repositoryId)) {
            return $this->invalidPathResponse(new InvalidBrowsePathException('Invalid backup repository id.'));
        }

        try {
            $this->restoreDirectoryBrowser->resolveDownloadFile($repositoryId, $path);
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }

        return new JsonResponse(['accessToken' => $this->downloadToken->issue($repositoryId, $path)]);
    }

    /**
     * Reachable without an admin session on purpose: the browser navigates here directly, so the
     * single-use access token from downloadPrepare() is the credential. The path is re-validated
     * on redeem.
     */
    #[Route(
        path: '/api/_action/muwa/restore/download',
        name: 'api.action.muwa.restore.download',
        defaults: ['auth_required' => false],
        methods: ['GET']
    )]
    public function download(Request $request): Response
    {
        $accessToken = $request->query->get('accessToken');
        $payload = is_string($accessToken) ? $this->downloadToken->redeem($accessToken) : null;
        if ($payload === null) {
            return new JsonResponse(
                ['errors' => [['code' => 'MUWA_INVALID_DOWNLOAD_TOKEN', 'detail' => 'Invalid or expired download token.']]],
                Response::HTTP_NOT_FOUND
            );
        }

        if (!Uuid::isValid($payload['backupRepositoryId'])) {
            return $this->invalidPathResponse(new InvalidBrowsePathException('Invalid backup repository id.'));
        }

        try {
            $absolutePath = $this->restoreDirectoryBrowser->resolveDownloadFile(
                $payload['backupRepositoryId'],
                $payload['path']
            );
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($absolutePath));

        return $response;
    }

    /**
     * Non-string values (arrays, numbers, ...) count as not provided.
     */
    protected function getString(RequestDataBag $data, string $key): ?string
    {
        $value = $data->get($key);

        return is_string($value) ? $value : null;
    }

    protected function invalidPathResponse(InvalidBrowsePathException $exception): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => 'MUWA_INVALID_PATH', 'detail' => $exception->getMessage()]]],
            Response::HTTP_BAD_REQUEST
        );
    }
}
