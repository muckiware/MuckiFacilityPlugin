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
namespace MuckiFacilityPlugin\Core\Content\S3Connection;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryCollection;

class S3ConnectionEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    /**
     * @var string one of MuckiFacilityPlugin\Core\S3Providers (aws, minio, ceph, other)
     */
    protected string $provider;

    protected ?string $endpoint = null;

    protected ?string $region = null;

    protected string $accessKeyId;

    protected ?string $secretAccessKey = null;

    /**
     * @var string one of MuckiFacilityPlugin\Core\PasswordSource (encrypted, env, file)
     */
    protected string $secretSource;

    protected ?BackupRepositoryCollection $backupRepositories = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): void { $this->provider = $provider; }

    public function getEndpoint(): ?string { return $this->endpoint; }
    public function setEndpoint(?string $endpoint): void { $this->endpoint = $endpoint; }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): void { $this->region = $region; }

    public function getAccessKeyId(): string { return $this->accessKeyId; }
    public function setAccessKeyId(string $accessKeyId): void { $this->accessKeyId = $accessKeyId; }

    public function getSecretAccessKey(): ?string { return $this->secretAccessKey; }
    public function setSecretAccessKey(?string $secretAccessKey): void { $this->secretAccessKey = $secretAccessKey; }

    public function getSecretSource(): string { return $this->secretSource; }
    public function setSecretSource(string $secretSource): void { $this->secretSource = $secretSource; }

    public function getBackupRepositories(): ?BackupRepositoryCollection { return $this->backupRepositories; }
    public function setBackupRepositories(?BackupRepositoryCollection $backupRepositories): void
    {
        $this->backupRepositories = $backupRepositories;
    }
}
