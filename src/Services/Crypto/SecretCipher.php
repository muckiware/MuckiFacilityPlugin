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
namespace MuckiFacilityPlugin\Services\Crypto;

use Shopware\Core\DevOps\Environment\EnvironmentHelper;

use MuckiFacilityPlugin\Exception\MissingFacilitySecretException;

/**
 * Symmetrische Verschluesselung fuer Repository-Passwoerter.
 *
 * Der Schluessel wird aus der Umgebungsvariablen MUWA_FACILITY_SECRET abgeleitet — bewusst
 * nicht aus APP_SECRET: APP_SECRET ist nicht als Verschluesselungsschluessel gedacht, und eine
 * Rotation davon wuerde saemtliche Repository-Passwoerter unbrauchbar machen.
 *
 * MUWA_FACILITY_SECRET gehoert damit ins Disaster-Recovery-Runbook. Ohne dieses Geheimnis
 * laesst sich auf einem neu aufgesetzten Host kein verschluesseltes Passwort mehr lesen.
 */
class SecretCipher
{
    /**
     * Versionspraefix. Erlaubt spaeter ein anderes Verfahren, ohne Altbestand zu verlieren.
     */
    public const CIPHER_PREFIX = 'v1:';

    public const SECRET_ENV_NAME = 'MUWA_FACILITY_SECRET';

    /**
     * Kuerzere Geheimnisse werden abgelehnt — das Verfahren streckt nicht, es leitet nur ab.
     */
    public const MINIMUM_SECRET_LENGTH = 32;

    /**
     * Trennt die Schluesselableitung von anderen Verwendungen desselben Geheimnisses.
     */
    private const KEY_CONTEXT = 'MuckiFacilityPlugin:repository-password:v1';

    /**
     * @param string|null $secret Nur fuer Tests. Ohne Wert greift die Umgebungsvariable.
     */
    public function __construct(
        protected ?string $secret = null
    )
    {}

    /**
     * Ist der Wert bereits verschluesselt.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::CIPHER_PREFIX);
    }

    /**
     * @throws MissingFacilitySecretException
     */
    public function encrypt(string $plainPassword): string
    {
        $key = $this->getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($plainPassword, $nonce, $key);

        sodium_memzero($key);

        return self::CIPHER_PREFIX . base64_encode($nonce . $cipherText);
    }

    /**
     * @throws MissingFacilitySecretException|\RuntimeException
     */
    public function decrypt(string $storedValue): string
    {
        if (!$this->isEncrypted($storedValue)) {
            throw new \RuntimeException(
                'Stored repository password is not encrypted, missing prefix "' . self::CIPHER_PREFIX . '"'
            );
        }

        $rawValue = base64_decode(substr($storedValue, strlen(self::CIPHER_PREFIX)), true);
        if ($rawValue === false || strlen($rawValue) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored repository password is malformed');
        }

        $key = $this->getKey();
        $plainPassword = sodium_crypto_secretbox_open(
            substr($rawValue, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($rawValue, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        sodium_memzero($key);

        if ($plainPassword === false) {
            throw new \RuntimeException(
                'Repository password could not be decrypted. Either ' . self::SECRET_ENV_NAME
                . ' does not match the one used for encryption, or the stored value was modified.'
            );
        }

        return $plainPassword;
    }

    /**
     * @throws MissingFacilitySecretException
     */
    protected function getKey(): string
    {
        return sodium_crypto_generichash(
            self::KEY_CONTEXT . $this->getSecret(),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    /**
     * @throws MissingFacilitySecretException
     */
    protected function getSecret(): string
    {
        $secret = $this->secret ?? (string) EnvironmentHelper::getVariable(
            self::SECRET_ENV_NAME,
            getenv(self::SECRET_ENV_NAME)
        );
        $secret = trim($secret);

        if ($secret === '') {
            throw new MissingFacilitySecretException(
                sprintf(
                    'Environment variable %s is not set. Generate one with '
                    . '"openssl rand -hex 32" and add it to your .env file.',
                    self::SECRET_ENV_NAME
                )
            );
        }

        if (strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new MissingFacilitySecretException(
                sprintf(
                    'Environment variable %s must be at least %d characters long.',
                    self::SECRET_ENV_NAME,
                    self::MINIMUM_SECRET_LENGTH
                )
            );
        }

        return $secret;
    }
}
