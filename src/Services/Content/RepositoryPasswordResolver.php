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

use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\UnresolvableRepositoryPasswordException;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

/**
 * Loest den gespeicherten Wert der Spalte `repository_password` zum Klartext-Passwort auf.
 *
 * Wie der Wert zu lesen ist, sagt `password_source`:
 *
 * | Quelle      | Inhalt der Spalte                        |
 * |-------------|------------------------------------------|
 * | `plain`     | das Passwort selbst (Altbestand)         |
 * | `encrypted` | Chiffretext mit Praefix `v1:`            |
 * | `env`       | Name einer Umgebungsvariablen            |
 * | `file`      | Pfad einer Datei mit dem Passwort        |
 *
 * Bei `env` und `file` steht kein Geheimnis in der Datenbank — ein geleakter Dump gibt dann
 * nicht einmal Chiffretext her.
 */
class RepositoryPasswordResolver
{
    public function __construct(
        protected SecretCipher $secretCipher
    )
    {}

    /**
     * @throws UnresolvableRepositoryPasswordException
     */
    public function resolve(string $storedValue, PasswordSource $passwordSource): string
    {
        $password = match ($passwordSource) {
            PasswordSource::PLAIN => $storedValue,
            PasswordSource::ENCRYPTED => $this->decrypt($storedValue),
            PasswordSource::ENV => $this->readFromEnvironment($storedValue),
            PasswordSource::FILE => $this->readFromFile($storedValue),
        };

        if ($password === '') {
            throw new UnresolvableRepositoryPasswordException(
                'Resolved repository password is empty for source "' . $passwordSource->value . '"'
            );
        }

        return $password;
    }

    /**
     * Bringt einen Klartext in die Form, die fuer die Quelle gespeichert wird.
     *
     * Bei `env` und `file` ist der uebergebene Wert bereits die Referenz und wird nur geprueft.
     *
     * @throws UnresolvableRepositoryPasswordException
     */
    public function prepareForStorage(string $inputValue, PasswordSource $passwordSource): string
    {
        if ($inputValue === '') {
            throw new UnresolvableRepositoryPasswordException('Repository password must not be empty');
        }

        if ($passwordSource === PasswordSource::ENCRYPTED) {
            try {
                return $this->secretCipher->encrypt($inputValue);
            } catch (\Exception $e) {
                throw new UnresolvableRepositoryPasswordException(
                    'Repository password could not be encrypted: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        // Referenzen und Altbestand werden unveraendert abgelegt, muessen aber aufloesbar sein.
        $this->resolve($inputValue, $passwordSource);

        return $inputValue;
    }

    /**
     * @throws UnresolvableRepositoryPasswordException
     */
    protected function decrypt(string $storedValue): string
    {
        try {
            return $this->secretCipher->decrypt($storedValue);
        } catch (\Exception $e) {
            throw new UnresolvableRepositoryPasswordException(
                'Repository password could not be decrypted: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @throws UnresolvableRepositoryPasswordException
     */
    protected function readFromEnvironment(string $variableName): string
    {
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $variableName)) {
            throw new UnresolvableRepositoryPasswordException(
                'Invalid environment variable name for repository password: ' . $variableName
            );
        }

        $password = (string) EnvironmentHelper::getVariable($variableName, getenv($variableName));

        if (trim($password) === '') {
            throw new UnresolvableRepositoryPasswordException(
                'Environment variable ' . $variableName . ' is not set or empty'
            );
        }

        return $password;
    }

    /**
     * @throws UnresolvableRepositoryPasswordException
     */
    protected function readFromFile(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new UnresolvableRepositoryPasswordException(
                'Repository password file is missing or not readable: ' . $filePath
            );
        }

        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new UnresolvableRepositoryPasswordException(
                'Repository password file could not be read: ' . $filePath
            );
        }

        // Ein abschliessender Zeilenumbruch ist beim Anlegen per Shell die Regel und gehoert
        // nicht zum Passwort. Fuehrende Zeichen bleiben erhalten.
        return rtrim($fileContent, "\r\n");
    }
}
