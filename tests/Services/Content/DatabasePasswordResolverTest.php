<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use MuckiFacilityPlugin\Core\PasswordSource;
use MuckiFacilityPlugin\Exception\UnresolvableDatabasePasswordException;
use MuckiFacilityPlugin\Services\Content\DatabasePasswordResolver;
use MuckiFacilityPlugin\Services\Crypto\SecretCipher;

class DatabasePasswordResolverTest extends TestCase
{
    private const TEST_SECRET = '0123456789abcdef0123456789abcdef';

    private string $projectDir;

    private string $outsideFile;

    private DatabasePasswordResolver $resolver;

    /**
     * @var array<int, string>
     */
    private array $touchedEnvKeys = [];

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->projectDir = $base . '/muwa-dbpw-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/secrets', 0777, true);
        $this->outsideFile = $this->projectDir . '/composer.json';
        file_put_contents($this->outsideFile, 'outside-secret');

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->resolver = new DatabasePasswordResolver(new SecretCipher(self::TEST_SECRET), $kernel);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnvKeys as $envKey) {
            unset($_SERVER[$envKey], $_ENV[$envKey]);
        }
        $this->touchedEnvKeys = [];

        $this->removeDirectory($this->projectDir);
    }

    public function testEncryptedSourceRoundTrip(): void
    {
        $stored = $this->resolver->prepareForStorage('my-password', PasswordSource::ENCRYPTED);

        self::assertStringStartsWith(SecretCipher::CIPHER_PREFIX, $stored);
        self::assertSame('my-password', $this->resolver->resolve($stored, PasswordSource::ENCRYPTED));
    }

    public function testPasswordWithLineBreakIsRejectedOnStorage(): void
    {
        foreach (["pw\nuser=root", "pw\rx", "a\r\nb"] as $password) {
            try {
                $this->resolver->prepareForStorage($password, PasswordSource::ENCRYPTED);
                self::fail('Expected exception for a password with a line break');
            } catch (UnresolvableDatabasePasswordException $e) {
                self::assertStringNotContainsString('user=root', $e->getMessage());
            }
        }
        self::assertTrue(true);
    }

    public function testResolvedPasswordWithLineBreakIsRejected(): void
    {
        $stored = (new SecretCipher(self::TEST_SECRET))->encrypt("pw\nuser=root");
        try {
            $this->resolver->resolve($stored, PasswordSource::ENCRYPTED);
            self::fail('Expected exception for encrypted password with a line break');
        } catch (UnresolvableDatabasePasswordException $e) {
            self::assertStringNotContainsString('user=root', $e->getMessage());
        }

        $this->setEnvironmentVariable('MUWA_DB_NL', "pw\nuser=root");
        try {
            $this->resolver->resolve('MUWA_DB_NL', PasswordSource::ENV);
            self::fail('Expected exception for env password with a line break');
        } catch (UnresolvableDatabasePasswordException $e) {
            self::assertStringNotContainsString('user=root', $e->getMessage());
        }

        $file = $this->projectDir . '/var/secrets/nl.pw';
        file_put_contents($file, "pw\nuser=root\n");
        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->resolve($file, PasswordSource::FILE);
    }

    public function testPlainSourceIsRejected(): void
    {
        try {
            $this->resolver->resolve('plain-pw', PasswordSource::PLAIN);
            self::fail('resolve must reject PLAIN');
        } catch (UnresolvableDatabasePasswordException) {
            // expected
        }

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->prepareForStorage('plain-pw', PasswordSource::PLAIN);
    }

    public function testEnvSourceRequiresPrefix(): void
    {
        $this->setEnvironmentVariable('APP_SECRET', 'must-not-leak');
        $this->setEnvironmentVariable('MUWA_DB_SHOP', 'shop-pw');

        self::assertSame('shop-pw', $this->resolver->resolve('MUWA_DB_SHOP', PasswordSource::ENV));

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->resolve('APP_SECRET', PasswordSource::ENV);
    }

    public function testEnvSourceRejectsPrefixOnlyAndLowercasePrefix(): void
    {
        $this->setEnvironmentVariable('muwa_db_x', 'x-pw');

        foreach (['MUWA_DB_', 'muwa_db_x', 'MUWA_DB_A B'] as $name) {
            try {
                $this->resolver->resolve($name, PasswordSource::ENV);
                self::fail('Expected exception for ' . $name);
            } catch (UnresolvableDatabasePasswordException) {
                // expected
            }
        }
        self::assertTrue(true);
    }

    public function testPrepareForStorageVerifiesEnvReference(): void
    {
        $this->setEnvironmentVariable('MUWA_DB_SHOP', 'shop-pw');
        self::assertSame('MUWA_DB_SHOP', $this->resolver->prepareForStorage('MUWA_DB_SHOP', PasswordSource::ENV));

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->prepareForStorage('APP_SECRET', PasswordSource::ENV);
    }

    public function testFileSourceInsideSecretsDirIsResolved(): void
    {
        $file = $this->projectDir . '/var/secrets/db.pw';
        file_put_contents($file, "pw\n");

        self::assertSame('pw', $this->resolver->resolve($file, PasswordSource::FILE));
        self::assertSame($file, $this->resolver->prepareForStorage($file, PasswordSource::FILE));
    }

    public function testFileSourceOutsideSecretsDirIsRejected(): void
    {
        foreach (['/etc/hosts', $this->projectDir . '/var/secrets/../../composer.json', $this->outsideFile] as $path) {
            try {
                $this->resolver->resolve($path, PasswordSource::FILE);
                self::fail('Expected exception for ' . $path);
            } catch (UnresolvableDatabasePasswordException) {
                // expected
            }
        }

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->prepareForStorage('/etc/hosts', PasswordSource::FILE);
    }

    public function testRelativeFilePathIsResolvedAgainstProjectDir(): void
    {
        file_put_contents($this->projectDir . '/var/secrets/db.pw', "relative-pw\n");

        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);
        chdir('/');
        try {
            self::assertSame('relative-pw', $this->resolver->resolve('var/secrets/db.pw', PasswordSource::FILE));
            self::assertSame('var/secrets/db.pw', $this->resolver->prepareForStorage('var/secrets/db.pw', PasswordSource::FILE));
        } finally {
            chdir($workingDirectory);
        }
    }

    public function testRelativeFilePathOutsideSecretsDirIsRejected(): void
    {
        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->resolve('var/secrets/../../composer.json', PasswordSource::FILE);
    }

    public function testNulByteInReferenceIsRejected(): void
    {
        file_put_contents($this->projectDir . '/var/secrets/db.pw', 'pw');
        $this->setEnvironmentVariable('MUWA_DB_SHOP', 'shop-pw');

        foreach ([
            [$this->projectDir . "/var/secrets/db.pw\0.txt", PasswordSource::FILE],
            ["MUWA_DB_SHOP\0", PasswordSource::ENV],
            ["MUWA_DB_SHOP\n", PasswordSource::ENV],
        ] as [$reference, $source]) {
            try {
                $this->resolver->resolve($reference, $source);
                self::fail('Expected exception for ' . json_encode($reference));
            } catch (UnresolvableDatabasePasswordException) {
                // expected, and no \ValueError from realpath()
            }
        }
        self::assertTrue(true);
    }

    public function testFileMissingAndOutsideSecretsDirGiveIdenticalMessage(): void
    {
        $messages = [];
        foreach ([$this->projectDir . '/var/secrets/missing.pw', $this->outsideFile] as $path) {
            try {
                $this->resolver->resolve($path, PasswordSource::FILE);
                self::fail('Expected exception');
            } catch (UnresolvableDatabasePasswordException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
    }

    public function testFileSourceSymlinkOutsideSecretsDirIsRejected(): void
    {
        $link = $this->projectDir . '/var/secrets/link.pw';
        symlink($this->outsideFile, $link);

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->resolve($link, PasswordSource::FILE);
    }

    public function testFileSourceThrowsWhenSecretsDirIsMissing(): void
    {
        rmdir($this->projectDir . '/var/secrets');

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->resolve($this->projectDir . '/var/secrets/db.pw', PasswordSource::FILE);
    }

    public function testEmptyPasswordIsRejected(): void
    {
        $file = $this->projectDir . '/var/secrets/empty.pw';
        file_put_contents($file, "\n");

        try {
            $this->resolver->resolve($file, PasswordSource::FILE);
            self::fail('empty file content must be rejected');
        } catch (UnresolvableDatabasePasswordException) {
            // expected
        }

        $this->expectException(UnresolvableDatabasePasswordException::class);
        $this->resolver->prepareForStorage('', PasswordSource::ENCRYPTED);
    }

    public function testExceptionMessagesNeverContainThePassword(): void
    {
        $secret = 'S3cr3t-Passw0rd-XYZ';
        $this->setEnvironmentVariable('APP_SECRET', $secret);
        $link = $this->projectDir . '/var/secrets/link.pw';
        file_put_contents($this->outsideFile, $secret);
        symlink($this->outsideFile, $link);

        $attempts = [
            fn () => $this->resolver->resolve($secret, PasswordSource::PLAIN),
            fn () => $this->resolver->prepareForStorage($secret, PasswordSource::PLAIN),
            fn () => $this->resolver->resolve($secret, PasswordSource::ENCRYPTED),
            fn () => $this->resolver->resolve('APP_SECRET', PasswordSource::ENV),
            fn () => $this->resolver->resolve($link, PasswordSource::FILE),
            fn () => $this->resolver->resolve($this->outsideFile, PasswordSource::FILE),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Expected exception');
            } catch (UnresolvableDatabasePasswordException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    private function setEnvironmentVariable(string $key, string $value): void
    {
        $this->touchedEnvKeys[] = $key;
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
