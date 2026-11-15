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
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Services\ResticBinaryInfo;

#[Route(defaults: ['_routeScope' => ['api']])]
class ResticController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected ResticBinaryInfo $resticBinaryInfo,
    )
    {}

    /**
     * Path and version of the restic binary used with the saved plugin configuration. Shown on
     * the plugin configuration page, hence the system config privilege.
     */
    #[Route(
        path: '/api/_action/muwa/restic/info',
        name: 'api.action.muwa.restic.info',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function getInfo(Context $context): JsonResponse
    {
        return new JsonResponse($this->resticBinaryInfo->getInfo());
    }
}
