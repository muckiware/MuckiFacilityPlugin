<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

use MuckiSearchPlugin\Services\Content\IndexStructure as IndexStructureService;

#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('services-settings')]
class SaveBackupRepositoryConfigController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        protected IndexStructureService $indexStructureService
    )
    {}

    /**
     * @throws WriteException|\Exception
     */
    #[Route(
        path: '/api/_action/muwa/backup/repository/save-config',
        name: 'api.action.muwa_backup_repository.save-config',
        methods: ['POST']
    )]
    public function saveMappingsSettingsSettings(RequestDataBag $requestDataBag, Context $context): JsonResponse
    {
        $saveMappingsResults = $this->indexStructureService->saveMappingsSettingsByLanguageId(
            $this->getMappings($requestDataBag),
            $this->getSettings($requestDataBag),
            $requestDataBag->get('id'),
            $requestDataBag->get('languageId'),
            $context
        );

        return new JsonResponse($saveMappingsResults->getContext());
    }

    protected function getMappings(RequestDataBag $requestDataBag): array
    {
        /** @var RequestDataBag $mappings */
        $mappings = $requestDataBag->get('translated')->get('mappings');
        return $mappings->all();
    }

    protected function getSettings(RequestDataBag $requestDataBag): array
    {
        /** @var RequestDataBag $settings */
        $settings = $requestDataBag->get('translated')->get('settings');
        return $settings->all();
    }
}
