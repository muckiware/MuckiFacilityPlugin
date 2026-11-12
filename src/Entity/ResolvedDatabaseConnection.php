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
namespace MuckiFacilityPlugin\Entity;

/**
 * Plain connection data of the database to dump, set by a ResolveDatabaseConnectionEvent subscriber.
 *
 * The password is plain text and only kept in memory. Use getPassword() only to hand it to the
 * dumper/driver, never for output. `databaseUrl` is only set for the default connection
 * (DATABASE_URL); dumpers use it to keep the legacy behaviour for that connection.
 */
class ResolvedDatabaseConnection
{
    /**
     * @param array<string, mixed> $extra type specific parameters
     */
    public function __construct(
        protected string $type,
        protected string $host,
        protected ?int $port,
        protected string $databaseName,
        protected string $username,
        protected string $password,
        protected array $extra = [],
        protected ?string $databaseUrl = null
    )
    {}

    public function getType(): string
    {
        return $this->type;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Internal use only — hand it to the dumper/driver, never log or return it.
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    public function getDatabaseUrl(): ?string
    {
        return $this->databaseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'host' => $this->host,
            'port' => $this->port,
            'databaseName' => $this->databaseName,
            'username' => $this->username,
            'password' => '***',
            'extra' => $this->extra,
            'databaseUrl' => $this->databaseUrl === null ? null : '***',
        ];
    }
}
