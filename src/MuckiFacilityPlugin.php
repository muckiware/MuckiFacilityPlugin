<?php declare(strict_types=1);
/**
 * MuckiFacilityPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiFacility
 * @copyright  Copyright (c) 2024 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiFacilityPlugin;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Add dependencies from composer
 */
if(file_exists(dirname(__DIR__) . "/vendor/autoload.php")) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

class MuckiFacilityPlugin extends Plugin
{
    /**
     * @throws \Exception
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    /**
     * @throws \Exception
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    /**
     * @throws \Exception
     */
    public function update(UpdateContext $updateContext): void
    {
        // Update your plugin
    }

    public function postInstall(InstallContext $installContext): void
    {
        //postInstall
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        //postUpdate
    }
}