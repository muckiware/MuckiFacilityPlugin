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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

use MuckiFacilityPlugin\Core\Defaults as PluginDefaults;
use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Entity\ForgetTypes;
use MuckiFacilityPlugin\Entity\BackupPathEntity;

/**
 *
 */
class BackupRepositoryEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected string $entity;

    /**
     * @var bool
     */
    protected bool $active;

    /**
     * technical name
     * @var string
     */
    protected string $name;

    /**
     * @var string
     */
    protected string $type;

    /**
     * @var string
     */
    protected string $repositoryPath;

    /**
     * @var string
     */
    protected string $repositoryPassword;

    /**
     * @var string
     */
    protected string $restorePath;

    /**
     * @var array<BackupPathEntity>
     */
    protected array $backupPaths;

    /**
     * @var int
     */
    protected int $forgetDaily;
    /**
     * @var int
     */
    protected int $forgetWeekly;
    /**
     * @var int
     */
    protected int $forgetMonthly;
    /**
     * @var int
     */
    protected int $forgetYearly;

    /**
     * @var bool
     */
    protected bool $compress;

    /**
     * @var string
     */
    protected string $hostname = PluginDefaults::DEFAULT_REPOSITORY_HOST_NAME;

    /**
     * @return string
     */
    public function getEntity(): string
    {
        return $this->entity;
    }

    /**
     * @param string $entity
     */
    public function setEntity(string $entity): void
    {
        $this->entity = $entity;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    /**
     * @param string $repositoryPath
     * @return void
     */
    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = $repositoryPath;
    }

    /**
     * @return string
     */
    public function getRepositoryPassword(): string
    {
        return $this->repositoryPassword;
    }

    /**
     * @param string $repositoryPassword
     * @return void
     */
    public function setRepositoryPassword(string $repositoryPassword): void
    {
        $this->repositoryPassword = $repositoryPassword;
    }

    /**
     * @return string
     */
    public function getRestorePath(): string
    {
        return $this->restorePath;
    }

    /**
     * @param string $restorePath
     * @return void
     */
    public function setRestorePath(string $restorePath): void
    {
        $this->restorePath = $restorePath;
    }

    /**
     * @return array<BackupPathEntity>
     */
    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    /**
     * @param array<BackupPathEntity> $backupPaths
     * @return void
     */
    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
    }

    /**
     * @return bool
     */
    public function isCompress(): bool
    {
        return $this->compress;
    }

    /**
     * @param bool $compress
     * @return void
     */
    public function setCompress(bool $compress): void
    {
        $this->compress = $compress;
    }

    /**
     * @return int
     */
    public function getForgetDaily(): int
    {
        return $this->forgetDaily;
    }

    /**
     * @param int $forgetDaily
     * @return void
     */
    public function setForgetDaily(int $forgetDaily): void
    {
        $this->forgetDaily = $forgetDaily;
    }

    /**
     * @return int
     */
    public function getForgetWeekly(): int
    {
        return $this->forgetWeekly;
    }

    /**
     * @param int $forgetWeekly
     * @return void
     */
    public function setForgetWeekly(int $forgetWeekly): void
    {
        $this->forgetWeekly = $forgetWeekly;
    }

    /**
     * @return int
     */
    public function getForgetMonthly(): int
    {
        return $this->forgetMonthly;
    }

    /**
     * @param int $forgetMonthly
     * @return void
     */
    public function setForgetMonthly(int $forgetMonthly): void
    {
        $this->forgetMonthly = $forgetMonthly;
    }

    /**
     * @return int
     */
    public function getForgetYearly(): int
    {
        return $this->forgetYearly;
    }

    /**
     * @param int $forgetYearly
     * @return void
     */
    public function setForgetYearly(int $forgetYearly): void
    {
        $this->forgetYearly = $forgetYearly;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }
}
