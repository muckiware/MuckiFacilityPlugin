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

use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\UnresolvableS3SecretException;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

/**
 * Resolves the stored secret access key of an S3 connection to its plain text.
 *
 * A secret reference is entered by an administration
 * user. Without restrictions an `env` or `file` reference could be used to read arbitrary
 * secrets (APP_SECRET, /etc/passwd, ...) through the connection test or an attacker-controlled
 * S3 endpoint. Therefore:
 *
 * | Source      | Content of the column                                   |
 * |-------------|---------------------------------------------------------|
 * | `plain`     | not supported                                           |
 * | `encrypted` | cipher text with prefix `v1:`                           |
 * | `env`       | name of an environment variable, must start `MUWA_S3_`  |
 * | `file`      | path of a file inside `<project dir>/var/secrets`       |
 *
 * A relative `file` path is resolved against the project dir (not the process working directory,
 * which differs between web and CLI), so `var/secrets/s3.pw` works everywhere.
 */
class S3SecretResolver
{
    public const ENV_PREFIX = 'MUWA_S3_';
    public const SECRETS_DIRECTORY = '/var/secrets';

    public function __construct(
        protected SecretCipher $secretCipher,
        protected KernelInterface $kernel
    )
    {}

    /**
     * @throws UnresolvableS3SecretException
     */
    public function resolve(string $storedValue, PasswordSource $source): string
    {
        $password = match ($source) {
            PasswordSource::PLAIN => throw new UnresolvableS3SecretException(
                'Password source "plain" is not supported for S3 connections'
            ),
            PasswordSource::ENCRYPTED => $this->decrypt($storedValue),
            PasswordSource::ENV => $this->readFromEnvironment($storedValue),
            PasswordSource::FILE => $this->readFromFile($storedValue),
        };

        if ($password === '') {
            throw new UnresolvableS3SecretException(
                'Resolved S3 secret access key is empty for source "' . $source->value . '"'
            );
        }

        $this->assertNoLineBreak($password, 'Resolved S3 secret access key (source "' . $source->value . '")');

        return $password;
    }

    /**
     * Brings a plain text into the form that is stored for the source.
     *
     * For `env` and `file` the given value already is the reference; it is only validated.
     *
     * @throws UnresolvableS3SecretException
     */
    public function prepareForStorage(string $inputValue, PasswordSource $source): string
    {
        if ($source === PasswordSource::PLAIN) {
            throw new UnresolvableS3SecretException(
                'Password source "plain" is not supported for S3 connections'
            );
        }

        if ($inputValue === '') {
            throw new UnresolvableS3SecretException('S3 secret access key must not be empty');
        }

        $this->assertNoLineBreak($inputValue, 'S3 secret access key');

        if ($source === PasswordSource::ENCRYPTED) {
            try {
                return $this->secretCipher->encrypt($inputValue);
            } catch (\Exception $e) {
                throw new UnresolvableS3SecretException(
                    'S3 secret access key could not be encrypted: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        // Reference: must be allowed and resolvable.
        $this->resolve($inputValue, $source);

        return $inputValue;
    }

    /**
     * The key ends up in HTTP request signing and may be passed on via environment variables; a line
     * break or NUL byte would corrupt the value or allow injection. The message never contains the value.
     *
     * @throws UnresolvableS3SecretException
     */
    protected function assertNoLineBreak(string $value, string $label): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new UnresolvableS3SecretException($label . ' must not contain line breaks or NUL bytes');
        }
    }

    /**
     * @throws UnresolvableS3SecretException
     */
    protected function decrypt(string $storedValue): string
    {
        try {
            return $this->secretCipher->decrypt($storedValue);
        } catch (\Exception $e) {
            throw new UnresolvableS3SecretException(
                'S3 secret access key could not be decrypted: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @throws UnresolvableS3SecretException
     */
    protected function readFromEnvironment(string $variableName): string
    {
        // D: "$" must not accept a trailing line break
        if (!preg_match('/^' . self::ENV_PREFIX . '[A-Z0-9_]+$/D', $variableName)) {
            throw new UnresolvableS3SecretException(
                'Environment variable for a S3 secret access key must match ' . self::ENV_PREFIX . '[A-Z0-9_]+'
            );
        }

        $password = (string) EnvironmentHelper::getVariable($variableName, getenv($variableName));

        if (trim($password) === '') {
            throw new UnresolvableS3SecretException(
                'Environment variable ' . $variableName . ' is not set or empty'
            );
        }

        return $password;
    }

    /**
     * @throws UnresolvableS3SecretException
     */
    protected function readFromFile(string $filePath): string
    {
        $projectDir = $this->kernel->getProjectDir();
        $secretsDirectory = realpath($projectDir . self::SECRETS_DIRECTORY);
        // realpath() throws a \ValueError on NUL bytes; treat them like any other invalid path.
        $realPath = $filePath === '' || str_contains($filePath, "\0")
            ? false
            : realpath(str_starts_with($filePath, '/') ? $filePath : $projectDir . '/' . $filePath);

        // One message for "missing" and "outside": no file-existence oracle for administration users.
        if (
            $secretsDirectory === false
            || $realPath === false
            || !is_file($realPath)
            // realpath() resolved symlinks and "..", so this also rejects links pointing outside.
            || !str_starts_with($realPath, $secretsDirectory . '/')
        ) {
            throw new UnresolvableS3SecretException(
                'S3 secret access key file is missing or not inside ' . self::SECRETS_DIRECTORY
            );
        }

        $fileContent = is_readable($realPath) ? file_get_contents($realPath) : false;
        if ($fileContent === false) {
            throw new UnresolvableS3SecretException(
                'S3 secret access key file could not be read: ' . $filePath
            );
        }

        return rtrim($fileContent, "\r\n");
    }
}
