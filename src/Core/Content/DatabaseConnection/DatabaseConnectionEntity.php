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
namespace MuckiFacilityPlugin\Core\Content\DatabaseConnection;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryCollection;

class DatabaseConnectionEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    protected string $type;

    protected string $host;

    protected ?int $port = null;

    protected string $databaseName;

    protected string $username;

    protected string $password;

    /**
     * @var string one of MuckiFacilityPlugin\Core\PasswordSource (encrypted, env, file)
     */
    protected string $passwordSource;

    protected ?BackupRepositoryCollection $backupRepositories = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): void { $this->type = $type; }

    public function getHost(): string { return $this->host; }
    public function setHost(string $host): void { $this->host = $host; }

    public function getPort(): ?int { return $this->port; }
    public function setPort(?int $port): void { $this->port = $port; }

    public function getDatabaseName(): string { return $this->databaseName; }
    public function setDatabaseName(string $databaseName): void { $this->databaseName = $databaseName; }

    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): void { $this->username = $username; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function getPasswordSource(): string { return $this->passwordSource; }
    public function setPasswordSource(string $passwordSource): void { $this->passwordSource = $passwordSource; }

    public function getBackupRepositories(): ?BackupRepositoryCollection { return $this->backupRepositories; }
    public function setBackupRepositories(?BackupRepositoryCollection $backupRepositories): void
    {
        $this->backupRepositories = $backupRepositories;
    }
}
