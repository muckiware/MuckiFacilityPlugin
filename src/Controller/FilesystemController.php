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
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Exception\InvalidBrowsePathException;
use MuckiFacilityPlugin\Services\DirectoryBrowser;

#[Route(defaults: ['_routeScope' => ['api']])]
class FilesystemController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected DirectoryBrowser $directoryBrowser,
    )
    {}

    #[Route(
        path: '/api/_action/muwa/filesystem/directories',
        name: 'api.action.muwa.filesystem.directories',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function listDirectories(RequestDataBag $data, Context $context): JsonResponse
    {
        try {
            return new JsonResponse($this->directoryBrowser->listDirectories($this->getString($data, 'path')));
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }
    }

    #[Route(
        path: '/api/_action/muwa/filesystem/files',
        name: 'api.action.muwa.filesystem.files',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function listFiles(RequestDataBag $data, Context $context): JsonResponse
    {
        try {
            return new JsonResponse($this->directoryBrowser->listFiles(
                $this->getString($data, 'path'),
                ['sh']
            ));
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }
    }

    #[Route(
        path: '/api/_action/muwa/filesystem/check-dump-path',
        name: 'api.action.muwa.filesystem.check_dump_path',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['POST']
    )]
    public function checkDumpPath(RequestDataBag $data, Context $context): JsonResponse
    {
        try {
            return new JsonResponse($this->directoryBrowser->checkDumpPath(
                $this->getString($data, 'dbDumpPath'),
                $this->getString($data, 'repositoryPath'),
                $this->getString($data, 'restorePath')
            ));
        } catch (InvalidBrowsePathException $exception) {
            return $this->invalidPathResponse($exception);
        }
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
