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
namespace MuckiFacilityPlugin\Core\Content\BackupRepository;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

use MuckiFacilityPlugin\Core\BackupTypes;
use MuckiFacilityPlugin\Entity\ForgetTypes;

class BackupRepositoryEntity extends Entity
{
    use EntityIdTrait;

    protected bool $active;

    /**
     * technical name
     * @var string
     */
    protected string $name;

    protected string $type;

    protected string $repositoryPath;

    protected string $repositoryPassword;

    protected string $restorePath;

    protected array $backupPaths;

    protected ForgetTypes $forgetParameters;

    protected bool $compress;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    public function setRepositoryPath(string $repositoryPath): void
    {
        $this->repositoryPath = $repositoryPath;
    }

    public function getRepositoryPassword(): string
    {
        return $this->repositoryPassword;
    }

    public function setRepositoryPassword(string $repositoryPassword): void
    {
        $this->repositoryPassword = $repositoryPassword;
    }

    public function getRestorePath(): string
    {
        return $this->restorePath;
    }

    public function setRestorePath(string $restorePath): void
    {
        $this->restorePath = $restorePath;
    }

    public function getBackupPaths(): array
    {
        return $this->backupPaths;
    }

    public function setBackupPaths(array $backupPaths): void
    {
        $this->backupPaths = $backupPaths;
    }

    public function getForgetParameters(): ForgetTypes
    {
        return $this->forgetParameters;
    }

    public function setForgetParameters(ForgetTypes $forgetParameters): void
    {
        $this->forgetParameters = $forgetParameters;
    }

    public function isCompress(): bool
    {
        return $this->compress;
    }

    public function setCompress(bool $compress): void
    {
        $this->compress = $compress;
    }
}
