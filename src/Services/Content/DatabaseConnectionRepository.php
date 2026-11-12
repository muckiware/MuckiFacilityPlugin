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
namespace MuckiFacilityPlugin\Services\Content;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\InvalidDatabaseConnectionException;
use MuckiFacilityPlugin\Exception\UnresolvableDatabasePasswordException;

/**
 * Validates and writes database connections.
 *
 * All connection fields are WriteProtected (system scope), so every write runs scoped and this
 * class is the only write path.
 */
class DatabaseConnectionRepository
{
    public const MAX_TYPE_LENGTH = 32;

    protected const REQUIRED_FIELDS = ['name', 'host', 'databaseName', 'username'];

    /**
     * spatie/db-dumper writes host and user unescaped into the mysqldump credentials file; a line
     * break would allow to inject further options.
     */
    public const FORBIDDEN_CHARACTERS = ["\r", "\n", "\0"];

    protected const ALLOWED_SOURCES = [PasswordSource::ENCRYPTED, PasswordSource::ENV, PasswordSource::FILE];

    /**
     * @param EntityRepository<\MuckiFacilityPlugin\Core\Content\DatabaseConnection\DatabaseConnectionCollection> $databaseConnectionRepository
     */
    public function __construct(
        protected EntityRepository $databaseConnectionRepository,
        protected DatabasePasswordResolver $passwordResolver
    )
    {}

    public function getById(string $id): ?DatabaseConnectionEntity
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $databaseConnection = Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $systemContext) => $this->databaseConnectionRepository
                ->search(new Criteria([$id]), $systemContext)
                ->get($id)
        );

        return $databaseConnection instanceof DatabaseConnectionEntity ? $databaseConnection : null;
    }

    /**
     * Creates or updates a connection. An empty password keeps the stored one.
     *
     * @param array<string, mixed> $input
     * @return string id of the connection
     *
     * @throws InvalidDatabaseConnectionException
     */
    public function save(array $input, Context $context): string
    {
        $id = $this->extractId($input);
        $stored = $this->getById($id);

        $payload = $this->validateFields($input);
        $payload['id'] = $id;

        $password = (string) ($input['password'] ?? '');
        $source = $this->extractSource($input);
        if ($password === '' && $stored !== null && $stored->getPasswordSource() === $source->value) {
            // Keep the stored password and source untouched, but never for another target.
            if ($this->targetChanged($payload, $stored)) {
                throw new InvalidDatabaseConnectionException(
                    'Enter the password when host, port, type or username are changed'
                );
            }
        } elseif ($password === '' && $stored !== null) {
            throw new InvalidDatabaseConnectionException('Enter the password when the password source is changed');
        } else {
            $payload['password'] = $this->prepareStoredPassword($password, $source);
            $payload['passwordSource'] = $source->value;
        }

        $context->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($stored, $payload): void {
                if ($stored === null) {
                    $this->databaseConnectionRepository->create([$payload], $systemScopedContext);
                    return;
                }
                $this->databaseConnectionRepository->update([$payload], $systemScopedContext);
            }
        );

        return $id;
    }

    /**
     * Builds an unsaved entity from form values (connection test). Fields missing in the input
     * and an empty password fall back to the stored connection.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidDatabaseConnectionException
     * @throws UnresolvableDatabasePasswordException
     */
    public function buildTransient(array $input, ?DatabaseConnectionEntity $stored): DatabaseConnectionEntity
    {
        if ($stored !== null) {
            $input += [
                'name' => $stored->getName(),
                'type' => $stored->getType(),
                'host' => $stored->getHost(),
                'port' => $stored->getPort(),
                'databaseName' => $stored->getDatabaseName(),
                'username' => $stored->getUsername(),
                'passwordSource' => $stored->getPasswordSource(),
            ];
        }

        $fields = $this->validateFields($input);
        $source = $this->extractSource($input);
        $password = (string) ($input['password'] ?? '');

        if ($password === '' && $stored !== null && $stored->getPasswordSource() === $source->value) {
            if ($this->targetChanged($fields, $stored)) {
                throw new UnresolvableDatabasePasswordException(
                    'Enter the password when host, port, type or username are changed'
                );
            }
            $storedPassword = $stored->getPassword();
        } else {
            $storedPassword = $password === ''
                ? throw new UnresolvableDatabasePasswordException('Database password must not be empty')
                : $this->passwordResolver->prepareForStorage($password, $source);
        }

        $entity = new DatabaseConnectionEntity();
        $entity->setId($stored?->getId() ?? Uuid::randomHex());
        $entity->setName($fields['name']);
        $entity->setType($fields['type']);
        $entity->setHost($fields['host']);
        $entity->setPort($fields['port']);
        $entity->setDatabaseName($fields['databaseName']);
        $entity->setUsername($fields['username']);
        $entity->setPassword($storedPassword);
        $entity->setPasswordSource($source->value);

        return $entity;
    }

    /**
     * The stored password must not be sent to another host or user than the one it belongs to.
     *
     * @param array{type: string, host: string, port: ?int, username: string} $fields
     */
    protected function targetChanged(array $fields, DatabaseConnectionEntity $stored): bool
    {
        return $fields['host'] !== $stored->getHost()
            || $fields['port'] !== $stored->getPort()
            || $fields['type'] !== strtolower($stored->getType())
            || $fields['username'] !== $stored->getUsername();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidDatabaseConnectionException
     */
    public function extractId(array $input): string
    {
        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '') {
            return Uuid::randomHex();
        }
        if (!Uuid::isValid($id)) {
            throw new InvalidDatabaseConnectionException('Invalid database connection id');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, type: string, host: string, port: ?int, databaseName: string, username: string}
     *
     * @throws InvalidDatabaseConnectionException
     */
    protected function validateFields(array $input): array
    {
        $fields = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $fields[$field] = trim((string) ($input[$field] ?? ''));
            if ($fields[$field] === '') {
                throw new InvalidDatabaseConnectionException(sprintf('Field "%s" must not be empty', $field));
            }
            if (self::containsForbiddenCharacter($fields[$field])) {
                throw new InvalidDatabaseConnectionException(
                    sprintf('Field "%s" must not contain line breaks or NUL bytes', $field)
                );
            }
        }

        $type = strtolower(trim((string) ($input['type'] ?? '')));
        if ($type === '' || strlen($type) > self::MAX_TYPE_LENGTH) {
            throw new InvalidDatabaseConnectionException(
                sprintf('Type must not be empty and at most %d characters long', self::MAX_TYPE_LENGTH)
            );
        }

        return [
            'name' => $fields['name'],
            'type' => $type,
            'host' => $fields['host'],
            'port' => $this->extractPort($input['port'] ?? null),
            'databaseName' => $fields['databaseName'],
            'username' => $fields['username'],
        ];
    }

    public static function containsForbiddenCharacter(string $value): bool
    {
        foreach (self::FORBIDDEN_CHARACTERS as $character) {
            if (str_contains($value, $character)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidDatabaseConnectionException
     */
    protected function extractPort(mixed $port): ?int
    {
        if ($port === null || (is_string($port) && trim($port) === '')) {
            return null;
        }

        $portNumber = is_int($port) ? $port : filter_var(is_string($port) ? trim($port) : $port, FILTER_VALIDATE_INT);
        if (!is_int($portNumber) || $portNumber < 1 || $portNumber > 65535) {
            throw new InvalidDatabaseConnectionException('Port must be empty or between 1 and 65535');
        }

        return $portNumber;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidDatabaseConnectionException
     */
    protected function extractSource(array $input): PasswordSource
    {
        $source = PasswordSource::tryFrom((string) ($input['passwordSource'] ?? ''));
        if ($source === null || !in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidDatabaseConnectionException('Password source must be one of: encrypted, env, file');
        }

        return $source;
    }

    /**
     * @throws InvalidDatabaseConnectionException
     */
    protected function prepareStoredPassword(string $password, PasswordSource $source): string
    {
        if ($password === '') {
            throw new InvalidDatabaseConnectionException('Database password must not be empty');
        }

        try {
            return $this->passwordResolver->prepareForStorage($password, $source);
        } catch (UnresolvableDatabasePasswordException $e) {
            throw new InvalidDatabaseConnectionException($e->getMessage(), 0, $e);
        }
    }
}
