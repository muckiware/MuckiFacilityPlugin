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

use MuckiFacilityPlugin\Exception\InvalidS3ConnectionException;
use MuckiFacilityPlugin\Exception\UnresolvableS3SecretException;
use MuckiFacilityPlugin\Services\Content\S3ConnectionRepository;
use MuckiFacilityPlugin\Services\Mirror\ErrorSanitizer;
use MuckiFacilityPlugin\Services\Mirror\MirrorTargetValidator;
use MuckiFacilityPlugin\Services\Mirror\S3ClientFactory;

/**
 * Save and test S3 connections. The secret access key is never part of a response.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class S3ConnectionController extends AbstractController
{
    public const ERROR_CODE = 'MUWA_INVALID_S3_CONNECTION';

    /**
     * The connection test runs inside an admin request and must not hang on an unreachable endpoint.
     */
    public const TEST_HTTP_OPTIONS = ['connect_timeout' => 5, 'timeout' => 15];

    /**
     * @internal
     */
    public function __construct(
        protected S3ConnectionRepository $s3ConnectionRepository,
        protected S3ClientFactory $s3ClientFactory
    )
    {}

    /**
     * secretAccessKey/secretSource are WriteProtected, hence an own action route instead of the
     * generic entity API.
     */
    #[Route(
        path: '/api/_action/muwa/s3-connection/save',
        name: 'api.action.muwa.s3_connection.save',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_s3_connection:update']],
        methods: ['POST']
    )]
    public function save(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $input = $requestDataBag->all();

        try {
            $id = $this->s3ConnectionRepository->extractId($input);
            $input['id'] = $id;

            if (
                $this->s3ConnectionRepository->getById($id) === null
                && !$context->isAllowed('muwa_s3_connection:create')
            ) {
                return new JsonResponse(
                    ['errors' => [[
                        'code' => 'MUWA_MISSING_PRIVILEGE',
                        'detail' => 'Missing privilege muwa_s3_connection:create',
                    ]]],
                    JsonResponse::HTTP_FORBIDDEN
                );
            }

            $this->s3ConnectionRepository->save($input, $context);
        } catch (InvalidS3ConnectionException $exception) {
            return $this->invalidResponse($exception);
        }

        return new JsonResponse(['success' => true, 'id' => $id]);
    }

    /**
     * Tests either a stored connection (only "id") or unsaved form values. With "bucket" the
     * bucket is checked (HeadBucket), otherwise the buckets are listed. A failing connection is a
     * regular result: HTTP 200 with success=false.
     */
    #[Route(
        path: '/api/_action/muwa/s3-connection/test',
        name: 'api.action.muwa.s3_connection.test',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_s3_connection:update']],
        methods: ['POST']
    )]
    public function testConnection(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $input = $requestDataBag->all();
        $secret = '';

        try {
            $rawBucket = $input['bucket'] ?? '';
            if (!is_string($rawBucket)) {
                throw new InvalidS3ConnectionException('Invalid S3 bucket name');
            }
            $bucket = trim($rawBucket);
            if ($bucket !== '' && !MirrorTargetValidator::isValidBucketName($bucket)) {
                throw new InvalidS3ConnectionException('Invalid S3 bucket name');
            }

            $stored = $this->s3ConnectionRepository->getById($this->s3ConnectionRepository->extractId($input));
            $connection = $this->s3ConnectionRepository->buildTransient($input, $stored);
            $secret = $this->s3ConnectionRepository->resolveSecret($connection);

            $client = $this->s3ClientFactory->create($connection, $secret, null, self::TEST_HTTP_OPTIONS);
            if ($bucket !== '') {
                $client->headBucket(['Bucket' => $bucket]);
            } else {
                $client->listBuckets();
            }
        } catch (InvalidS3ConnectionException $exception) {
            return $this->invalidResponse($exception);
        } catch (UnresolvableS3SecretException $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => ErrorSanitizer::sanitize(
                    $exception,
                    $secret,
                    is_string($input['secretAccessKey'] ?? null) ? $input['secretAccessKey'] : ''
                ),
            ]);
        }

        return new JsonResponse(['success' => true, 'message' => 'Connection successful']);
    }

    protected function invalidResponse(InvalidS3ConnectionException $exception): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => self::ERROR_CODE, 'detail' => $exception->getMessage()]]],
            JsonResponse::HTTP_BAD_REQUEST
        );
    }
}
