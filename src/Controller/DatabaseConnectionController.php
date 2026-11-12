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
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Exception\DatabaseBackupFailedException;
use MuckiFacilityPlugin\Exception\InvalidDatabaseConnectionException;
use MuckiFacilityPlugin\Exception\UnresolvableDatabasePasswordException;
use MuckiFacilityPlugin\Services\Content\DatabaseConnectionRepository;
use MuckiFacilityPlugin\Services\DatabaseConnectionResolver;

/**
 * Save and test database connections. The password is never part of a response.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class DatabaseConnectionController extends AbstractController
{
    public const ERROR_CODE = 'MUWA_INVALID_DATABASE_CONNECTION';

    /**
     * @internal
     */
    public function __construct(
        protected DatabaseConnectionRepository $databaseConnectionRepository,
        protected DatabaseConnectionResolver $databaseConnectionResolver
    )
    {}

    /**
     * password/passwordSource are WriteProtected, hence an own action route instead of the
     * generic entity API.
     */
    #[Route(
        path: '/api/_action/muwa/database-connection/save',
        name: 'api.action.muwa.database_connection.save',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_database_connection:update']],
        methods: ['POST']
    )]
    public function save(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $input = $requestDataBag->all();

        try {
            $id = $this->databaseConnectionRepository->extractId($input);
            $input['id'] = $id;

            if (
                $this->databaseConnectionRepository->getById($id) === null
                && !$context->isAllowed('muwa_database_connection:create')
            ) {
                return new JsonResponse(
                    ['errors' => [[
                        'code' => 'MUWA_MISSING_PRIVILEGE',
                        'detail' => 'Missing privilege muwa_database_connection:create',
                    ]]],
                    JsonResponse::HTTP_FORBIDDEN
                );
            }

            $this->databaseConnectionRepository->save($input, $context);
        } catch (InvalidDatabaseConnectionException $exception) {
            return $this->invalidResponse($exception);
        }

        return new JsonResponse(['success' => true, 'id' => $id]);
    }

    /**
     * Tests either a stored connection (only "id") or unsaved form values. A failing connection
     * is a regular result: HTTP 200 with success=false.
     */
    #[Route(
        path: '/api/_action/muwa/database-connection/test',
        name: 'api.action.muwa.database_connection.test',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_database_connection:update']],
        methods: ['POST']
    )]
    public function testConnection(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $input = $requestDataBag->all();

        try {
            $stored = $this->databaseConnectionRepository->getById(
                $this->databaseConnectionRepository->extractId($input)
            );
            $databaseConnection = $this->databaseConnectionRepository->buildTransient($input, $stored);

            $target = $this->databaseConnectionResolver->resolveConnection($databaseConnection, $context);
            $target->getDumper()->testConnection($target->getConnection());
        } catch (InvalidDatabaseConnectionException $exception) {
            return $this->invalidResponse($exception);
        } catch (UnresolvableDatabasePasswordException | DatabaseBackupFailedException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()]);
        }

        return new JsonResponse(['success' => true, 'message' => 'Connection successful']);
    }

    protected function invalidResponse(InvalidDatabaseConnectionException $exception): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => self::ERROR_CODE, 'detail' => $exception->getMessage()]]],
            JsonResponse::HTTP_BAD_REQUEST
        );
    }
}
