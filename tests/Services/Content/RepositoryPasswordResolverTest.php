<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\UnresolvableRepositoryPasswordException;
use MuckiFacilityPlugin\Services\Content\RepositoryPasswordResolver;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

class RepositoryPasswordResolverTest extends TestCase
{
    private const TEST_SECRET = '0123456789abcdef0123456789abcdef';

    private RepositoryPasswordResolver $resolver;

    /**
     * @var array<int, string>
     */
    private array $touchedEnvKeys = [];

    /**
     * @var array<int, string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = new RepositoryPasswordResolver(new SecretCipher(self::TEST_SECRET));
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $envKey) {
            unset($_SERVER[$envKey], $_ENV[$envKey]);
        }
        $this->touchedEnvKeys = [];

        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
        $this->tempFiles = [];
    }

    public function testPlainSourceReturnsStoredValueUnchanged(): void
    {
        self::assertSame(
            'legacy-password',
            $this->resolver->resolve('legacy-password', PasswordSource::PLAIN)
        );
    }

    public function testEncryptedSourceRoundTrip(): void
    {
        $stored = $this->resolver->prepareForStorage('my-password', PasswordSource::ENCRYPTED);

        self::assertStringStartsWith(SecretCipher::CIPHER_PREFIX, $stored);
        self::assertSame('my-password', $this->resolver->resolve($stored, PasswordSource::ENCRYPTED));
    }

    public function testEncryptedSourceRejectsUnencryptedStoredValue(): void
    {
        // Sonst wuerde ein faelschlich auf `encrypted` stehender Klartext als Passwort
        // durchgereicht und die Verschluesselung waere still wirkungslos.
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->resolve('not-encrypted', PasswordSource::ENCRYPTED);
    }

    public function testEnvSourceReadsEnvironmentVariable(): void
    {
        $this->setEnvironmentVariable('MUWA_TEST_REPO_PASSWORD', 'from-environment');

        self::assertSame(
            'from-environment',
            $this->resolver->resolve('MUWA_TEST_REPO_PASSWORD', PasswordSource::ENV)
        );
    }

    public function testEnvSourceThrowsWhenVariableIsMissing(): void
    {
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->resolve('MUWA_TEST_DOES_NOT_EXIST', PasswordSource::ENV);
    }

    public function testEnvSourceRejectsInvalidVariableName(): void
    {
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->resolve('not a valid name', PasswordSource::ENV);
    }

    public function testFileSourceReadsFileAndStripsTrailingNewline(): void
    {
        $passwordFile = $this->createTempFile("file-password\n");

        self::assertSame(
            'file-password',
            $this->resolver->resolve($passwordFile, PasswordSource::FILE)
        );
    }

    public function testFileSourceKeepsInnerWhitespace(): void
    {
        $passwordFile = $this->createTempFile(' pass word ');

        self::assertSame(
            ' pass word ',
            $this->resolver->resolve($passwordFile, PasswordSource::FILE)
        );
    }

    public function testFileSourceThrowsWhenFileIsMissing(): void
    {
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->resolve('/does/not/exist/password.txt', PasswordSource::FILE);
    }

    public function testResolveThrowsOnEmptyResult(): void
    {
        $passwordFile = $this->createTempFile("\n");

        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->resolve($passwordFile, PasswordSource::FILE);
    }

    public function testPrepareForStorageRejectsEmptyInput(): void
    {
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->prepareForStorage('', PasswordSource::ENCRYPTED);
    }

    public function testPrepareForStorageKeepsReferenceAndVerifiesItResolves(): void
    {
        $this->setEnvironmentVariable('MUWA_TEST_REPO_PASSWORD', 'from-environment');

        self::assertSame(
            'MUWA_TEST_REPO_PASSWORD',
            $this->resolver->prepareForStorage('MUWA_TEST_REPO_PASSWORD', PasswordSource::ENV)
        );
    }

    public function testPrepareForStorageRejectsUnresolvableReference(): void
    {
        // Eine kaputte Referenz soll beim Setzen auffallen, nicht erst beim naechsten Backup.
        $this->expectException(UnresolvableRepositoryPasswordException::class);
        $this->resolver->prepareForStorage('/does/not/exist/password.txt', PasswordSource::FILE);
    }

    private function setEnvironmentVariable(string $key, string $value): void
    {
        $this->touchedEnvKeys[] = $key;
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    private function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'muwa-password-');
        self::assertIsString($tempFile);
        file_put_contents($tempFile, $content);
        $this->tempFiles[] = $tempFile;

        return $tempFile;
    }
}
