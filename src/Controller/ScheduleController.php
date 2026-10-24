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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use MuckiFacilityPlugin\Schedule\Exception\BackupRepositoryNotFoundException;
use MuckiFacilityPlugin\Schedule\ScheduleConfiguration;

#[Route(defaults: ['_routeScope' => ['api']])]
final class ScheduleController extends AbstractController
{
    public function __construct(private readonly ScheduleConfiguration $configuration) {}
    #[Route(
        path: '/api/_action/muwa/backup/{backupRepositoryId}/schedule',
        name: 'api.action.muwa.backup.schedule',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_backup_repository:update']],
        methods: ['PUT']
    )]
    public function configure(string $backupRepositoryId, RequestDataBag $data, Context $context): Response
    {
        try {
            $this->configuration->configure(
                $backupRepositoryId,
                (bool) $data->get('enabled'),
                (string) $data->get('cronExpression'),
                (string) ($data->get('timezone') ?: 'UTC')
            );
        } catch (BackupRepositoryNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
