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

use MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionEntity;
use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Core\S3Providers;
use MuckiFacilityPlugin\Exception\InvalidS3ConnectionException;
use MuckiFacilityPlugin\Exception\UnresolvableS3SecretException;

/**
 * Validates and writes S3 connections.
 *
 * All connection fields are WriteProtected (system scope), so every write runs scoped and this
 * class is the only write path.
 */
class S3ConnectionRepository
{
    protected const REQUIRED_FIELDS = ['name', 'provider', 'accessKeyId'];

    protected const OPTIONAL_FIELDS = ['endpoint', 'region'];

    protected const ALLOWED_SOURCES = [PasswordSource::ENCRYPTED, PasswordSource::ENV, PasswordSource::FILE];

    /**
     * @param EntityRepository<\MuckiFacilityPlugin\Core\Content\S3Connection\S3ConnectionCollection> $s3ConnectionRepository
     */
    public function __construct(
        protected EntityRepository $s3ConnectionRepository,
        protected S3SecretResolver $secretResolver
    )
    {}

    public function getById(string $id): ?S3ConnectionEntity
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $connection = Context::createDefaultContext()->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $systemContext) => $this->s3ConnectionRepository
                ->search(new Criteria([$id]), $systemContext)
                ->get($id)
        );

        return $connection instanceof S3ConnectionEntity ? $connection : null;
    }

    /**
     * Creates or updates a connection. An empty secret keeps the stored one.
     *
     * @param array<string, mixed> $input
     * @return string id of the connection
     *
     * @throws InvalidS3ConnectionException
     */
    public function save(array $input, Context $context): string
    {
        $id = $this->extractId($input);
        $stored = $this->getById($id);

        $payload = $this->validateFields($input);
        $payload['id'] = $id;

        $secret = self::inputString($input, 'secretAccessKey');
        $source = $this->extractSource($input);
        if ($secret === '' && $stored !== null && $stored->getSecretSource() === $source->value) {
            if ($this->targetChanged($payload, $stored)) {
                throw new InvalidS3ConnectionException(
                    'Enter the secret access key when provider, endpoint, region or access key id are changed'
                );
            }
        } elseif ($secret === '' && $stored !== null) {
            throw new InvalidS3ConnectionException('Enter the secret access key when the secret source is changed');
        } else {
            $payload['secretAccessKey'] = $this->prepareStoredSecret($secret, $source);
            $payload['secretSource'] = $source->value;
        }

        $context->scope(
            Context::SYSTEM_SCOPE,
            function (Context $systemScopedContext) use ($stored, $payload): void {
                if ($stored === null) {
                    $this->s3ConnectionRepository->create([$payload], $systemScopedContext);
                    return;
                }
                $this->s3ConnectionRepository->update([$payload], $systemScopedContext);
            }
        );

        return $id;
    }

    /**
     * Builds an unsaved entity from form values (connection test). Fields missing in the input
     * and an empty secret fall back to the stored connection.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidS3ConnectionException
     * @throws UnresolvableS3SecretException
     */
    public function buildTransient(array $input, ?S3ConnectionEntity $stored): S3ConnectionEntity
    {
        if ($stored !== null) {
            $input += [
                'name' => $stored->getName(),
                'provider' => $stored->getProvider(),
                'endpoint' => $stored->getEndpoint(),
                'region' => $stored->getRegion(),
                'accessKeyId' => $stored->getAccessKeyId(),
                'secretSource' => $stored->getSecretSource(),
            ];
        }

        $fields = $this->validateFields($input);
        $source = $this->extractSource($input);
        $secret = self::inputString($input, 'secretAccessKey');

        if ($secret === '' && $stored !== null && $stored->getSecretSource() === $source->value) {
            if ($this->targetChanged($fields, $stored)) {
                throw new UnresolvableS3SecretException(
                    'Enter the secret access key when provider, endpoint, region or access key id are changed'
                );
            }
            $storedSecret = $stored->getSecretAccessKey();
        } else {
            $storedSecret = $secret === ''
                ? throw new UnresolvableS3SecretException('S3 secret access key must not be empty')
                : $this->secretResolver->prepareForStorage($secret, $source);
        }

        $entity = new S3ConnectionEntity();
        $entity->setId($stored?->getId() ?? Uuid::randomHex());
        $entity->setName($fields['name']);
        $entity->setProvider($fields['provider']);
        $entity->setEndpoint($fields['endpoint']);
        $entity->setRegion($fields['region']);
        $entity->setAccessKeyId($fields['accessKeyId']);
        $entity->setSecretAccessKey($storedSecret);
        $entity->setSecretSource($source->value);

        return $entity;
    }

    /**
     * Resolves the (stored or transient) secret of a connection to its plain text.
     *
     * @throws UnresolvableS3SecretException
     */
    public function resolveSecret(S3ConnectionEntity $connection): string
    {
        $source = PasswordSource::tryFrom($connection->getSecretSource());
        if ($source === null) {
            throw new UnresolvableS3SecretException('Unknown S3 secret source');
        }

        return $this->secretResolver->resolve((string) $connection->getSecretAccessKey(), $source);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidS3ConnectionException
     */
    public function extractId(array $input): string
    {
        $id = trim(self::inputString($input, 'id'));
        if ($id === '') {
            return Uuid::randomHex();
        }
        if (!Uuid::isValid($id)) {
            throw new InvalidS3ConnectionException('Invalid S3 connection id');
        }

        return $id;
    }

    /**
     * Reads a request body value as string. Arrays/objects are rejected (400) instead of being cast,
     * which would raise an "Array to string conversion" warning.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidS3ConnectionException
     */
    public static function inputString(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_scalar($value)) {
            throw new InvalidS3ConnectionException(sprintf('Field "%s" must be a string', $key));
        }

        return (string) $value;
    }

    /**
     * The stored secret must not be sent to another target or access key than the one it belongs to.
     *
     * @param array{provider: string, endpoint: ?string, region: ?string, accessKeyId: string} $fields
     */
    protected function targetChanged(array $fields, S3ConnectionEntity $stored): bool
    {
        return $fields['provider'] !== $stored->getProvider()
            || $fields['endpoint'] !== $this->normalize($stored->getEndpoint())
            || $fields['region'] !== $this->normalize($stored->getRegion())
            || $fields['accessKeyId'] !== $stored->getAccessKeyId();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, provider: string, endpoint: ?string, region: ?string, accessKeyId: string}
     *
     * @throws InvalidS3ConnectionException
     */
    protected function validateFields(array $input): array
    {
        $fields = [];
        foreach ([...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS] as $field) {
            $raw = self::inputString($input, $field);
            $fields[$field] = trim($raw);
            // Check the raw value: trim() would strip a trailing NUL byte or line break.
            if (DatabaseConnectionRepository::containsForbiddenCharacter($raw)) {
                throw new InvalidS3ConnectionException(
                    sprintf('Field "%s" must not contain line breaks or NUL bytes', $field)
                );
            }
        }
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($fields[$field] === '') {
                throw new InvalidS3ConnectionException(sprintf('Field "%s" must not be empty', $field));
            }
        }

        $provider = S3Providers::tryFrom($fields['provider']);
        if ($provider === null) {
            throw new InvalidS3ConnectionException('Provider must be one of: aws, minio, ceph, other');
        }
        if ($provider->requiresRegion() && $fields['region'] === '') {
            throw new InvalidS3ConnectionException('Field "region" must not be empty for this provider');
        }
        $this->assertEndpoint($fields['endpoint'], $provider);

        return [
            'name' => $fields['name'],
            'provider' => $provider->value,
            'endpoint' => $this->normalize($fields['endpoint']),
            'region' => $this->normalize($fields['region']),
            'accessKeyId' => $fields['accessKeyId'],
        ];
    }

    /**
     * @throws InvalidS3ConnectionException
     */
    protected function assertEndpoint(string $endpoint, S3Providers $provider): void
    {
        if ($endpoint === '') {
            if ($provider->requiresEndpoint()) {
                throw new InvalidS3ConnectionException('Field "endpoint" must not be empty for this provider');
            }
            return;
        }

        $allowedSchemes = in_array($provider, [S3Providers::MINIO, S3Providers::OTHER], true)
            ? ['https', 'http']
            : ['https'];
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, $allowedSchemes, true) || (string) ($parts['host'] ?? '') === '') {
            throw new InvalidS3ConnectionException(
                'Endpoint must be a URL starting with ' . implode(' or ', array_map(
                    static fn (string $s): string => $s . '://',
                    $allowedSchemes
                ))
            );
        }
    }

    protected function normalize(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidS3ConnectionException
     */
    protected function extractSource(array $input): PasswordSource
    {
        $source = PasswordSource::tryFrom(self::inputString($input, 'secretSource'));
        if ($source === null || !in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidS3ConnectionException('Secret source must be one of: encrypted, env, file');
        }

        return $source;
    }

    /**
     * @throws InvalidS3ConnectionException
     */
    protected function prepareStoredSecret(string $secret, PasswordSource $source): string
    {
        if ($secret === '') {
            throw new InvalidS3ConnectionException('S3 secret access key must not be empty');
        }

        try {
            return $this->secretResolver->prepareForStorage($secret, $source);
        } catch (UnresolvableS3SecretException $e) {
            throw new InvalidS3ConnectionException($e->getMessage(), 0, $e);
        }
    }
}
