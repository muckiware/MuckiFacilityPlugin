<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use MuckiFacilityPlugin\Services\Content\BackupRepository as BackupRepositoryService;
use MuckiFacilityPlugin\Entity\RepositoryInitInputs;
use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;

#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('create-backup-repository')]
class BackupController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        private readonly MessageBusInterface $messageBus
    )
    {}

    /**
     * @throws ExceptionInterface
     */
    #[Route(
        path: '/api/_action/muwa/backup/process',
        name: 'api.action.muwa.backup.process',
        methods: ['POST']
    )]
    public function process(RequestDataBag $requestDataBag, Context $context): Response
    {
        $message = new CreateBackupMessage();
        $message->setBackupRepositoryId($requestDataBag->get('id'));
        $this->messageBus->dispatch($message);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
