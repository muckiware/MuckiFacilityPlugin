<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Crypto;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Exception\MissingFacilitySecretException;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

class SecretCipherTest extends TestCase
{
    private const TEST_SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * @var array<int, string>
     */
    private array $touchedEnvKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $envKey) {
            unset($_SERVER[$envKey], $_ENV[$envKey]);
        }
        $this->touchedEnvKeys = [];
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = new SecretCipher(self::TEST_SECRET);
        $plainPassword = 'correct horse battery staple';

        self::assertSame($plainPassword, $cipher->decrypt($cipher->encrypt($plainPassword)));
    }

    public function testEncryptedValueCarriesPrefixAndHidesPlaintext(): void
    {
        $cipher = new SecretCipher(self::TEST_SECRET);
        $encrypted = $cipher->encrypt('super-secret');

        self::assertStringStartsWith(SecretCipher::CIPHER_PREFIX, $encrypted);
        self::assertStringNotContainsString('super-secret', $encrypted);
        self::assertTrue($cipher->isEncrypted($encrypted));
    }

    public function testSameInputProducesDifferentCiphertext(): void
    {
        $cipher = new SecretCipher(self::TEST_SECRET);

        // Zufalls-Nonce je Aufruf: gleiche Passwoerter duerfen in der Datenbank nicht als
        // gleicher Wert erkennbar sein.
        self::assertNotSame($cipher->encrypt('same'), $cipher->encrypt('same'));
    }

    public function testDecryptRejectsValueWithoutPrefix(): void
    {
        $cipher = new SecretCipher(self::TEST_SECRET);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt('plain-text-password');
    }

    public function testDecryptRejectsTamperedValue(): void
    {
        $cipher = new SecretCipher(self::TEST_SECRET);
        $encrypted = $cipher->encrypt('super-secret');

        $rawValue = base64_decode(substr($encrypted, strlen(SecretCipher::CIPHER_PREFIX)), true);
        self::assertIsString($rawValue);
        $rawValue[strlen($rawValue) - 1] = $rawValue[strlen($rawValue) - 1] === 'a' ? 'b' : 'a';
        $tampered = SecretCipher::CIPHER_PREFIX . base64_encode($rawValue);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function testDecryptFailsWithDifferentSecret(): void
    {
        $encrypted = (new SecretCipher(self::TEST_SECRET))->encrypt('super-secret');
        $otherCipher = new SecretCipher('fedcba9876543210fedcba9876543210');

        $this->expectException(\RuntimeException::class);
        $otherCipher->decrypt($encrypted);
    }

    public function testMissingSecretThrows(): void
    {
        $this->setEnvironmentVariable(SecretCipher::SECRET_ENV_NAME, '');

        $this->expectException(MissingFacilitySecretException::class);
        (new SecretCipher())->encrypt('super-secret');
    }

    public function testTooShortSecretThrows(): void
    {
        $this->expectException(MissingFacilitySecretException::class);
        (new SecretCipher('too-short'))->encrypt('super-secret');
    }

    public function testSecretIsReadFromEnvironmentWhenNotInjected(): void
    {
        $this->setEnvironmentVariable(SecretCipher::SECRET_ENV_NAME, self::TEST_SECRET);

        $cipher = new SecretCipher();

        self::assertSame('from-env', $cipher->decrypt($cipher->encrypt('from-env')));
    }

    private function setEnvironmentVariable(string $key, string $value): void
    {
        $this->touchedEnvKeys[] = $key;
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }
}
