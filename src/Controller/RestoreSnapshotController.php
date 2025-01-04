<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024-2025 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
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
use MuckiFacilityPlugin\Entity\BackupPathEntity;

#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('restore-snapshot-repository')]
class RestoreSnapshotController extends AbstractController
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
        path: '/api/_action/muwa/restore/process',
        name: 'api.action.muwa.restore.process',
        methods: ['POST']
    )]
    public function process(RequestDataBag $requestDataBag, Context $context): Response
    {
        $message = new CreateBackupMessage();
        $message->setBackupRepositoryId($requestDataBag->get('id'));
        $message->setRepositoryPassword($requestDataBag->get('repositoryPassword'));
        $message->setRepositoryPath($requestDataBag->get('repositoryPath'));
        $message->setBackupType($requestDataBag->get('type'));
        $message->setSnapshotId($requestDataBag->get('snapshotId'));

        $this->messageBus->dispatch($message);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
