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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\MessageQueue\Message\CreateBackupMessage;

#[Route(defaults: ['_routeScope' => ['api']])]
class BackupController extends AbstractController
{
    public function __construct(private readonly MessageBusInterface $messageBus) {}
    #[Route(
        path: '/api/_action/muwa/backup/process',
        name: 'api.action.muwa.backup.process',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:backup']],
        methods: ['POST']
    )]
    public function process(RequestDataBag $data, Context $context): Response
    {
        $this->messageBus->dispatch(new CreateBackupMessage((string) $data->get('id')));
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
