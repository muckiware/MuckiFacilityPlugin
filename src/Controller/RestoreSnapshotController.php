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
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;
use MuckiFacilityPlugin\Services\Content\BackupRepository;

#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('restore-snapshot-repository')]
class RestoreSnapshotController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        protected BackupRepository $backupRepository
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
        $backupRepository = $this->backupRepository->getBackupRepositoryById(
            $requestDataBag->get('backupRepositoryId')
        );

        if($backupRepository) {

            $message = new CreateBackupMessage();
            $message->setBackupRepositoryId($requestDataBag->get('backupRepositoryId'));
            $message->setRepositoryPassword($backupRepository->getRepositoryPassword());
            $message->setRepositoryPath($backupRepository->getRepositoryPath());
            $message->setRestoreTarget($backupRepository->getRestorePath());
            $message->setSnapshotId($requestDataBag->get('snapshotId'));

            $this->messageBus->dispatch($message);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
