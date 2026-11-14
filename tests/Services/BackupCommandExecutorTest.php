<?php declare(strict_types=1);
namespace MuckiFacilityPlugin\tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

use MuckiFacilityPlugin\Core\BackupCommandTypes;
use MuckiFacilityPlugin\Services\BackupCommandExecutor;

class BackupCommandExecutorTest extends TestCase
{
    private string $root;
    private BackupCommandExecutor $executor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/muwa-hooks-' . uniqid();
        mkdir($this->root . '/bin', 0777, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->root);
        $this->executor = new BackupCommandExecutor(
            $this->createMock(LoggerInterface::class),
            $kernel
        );
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->root);
    }

    public function testBatchScriptIsExecutedWithBash(): void
    {
        $marker = $this->root . '/batch-ran';
        $script = $this->root . '/hook.sh';
        file_put_contents($script, "#!/usr/bin/env bash\nprintf done > " . escapeshellarg($marker) . "\n");

        $this->executor->execute(BackupCommandTypes::BATCH->value, $script, true);

        static::assertSame('done', file_get_contents($marker));
    }

    public function testBatchScriptReceivesSeparateQuotedArguments(): void
    {
        $marker = $this->root . '/batch-args';
        $script = $this->root . '/hook.sh';
        file_put_contents(
            $script,
            "#!/usr/bin/env bash\nprintf '%s|%s|%s' \"$1\" \"$2\" \"$3\" > " . escapeshellarg($marker) . "\n"
        );

        $this->executor->execute(
            BackupCommandTypes::BATCH->value,
            $script,
            true,
            '/var/www/html "value with spaces" \'single quoted\''
        );

        static::assertSame('/var/www/html|value with spaces|single quoted', file_get_contents($marker));
    }

    public function testBatchArgumentsDoNotInvokeAShell(): void
    {
        $marker = $this->root . '/batch-args';
        $injected = $this->root . '/injected';
        $script = $this->root . '/hook.sh';
        file_put_contents(
            $script,
            "#!/usr/bin/env bash\nprintf '%s' \"$1\" > " . escapeshellarg($marker) . "\n"
        );

        $this->executor->execute(
            BackupCommandTypes::BATCH->value,
            $script,
            true,
            '; touch ' . $injected
        );

        static::assertSame(';', file_get_contents($marker));
        static::assertFileDoesNotExist($injected);
    }

    public function testMalformedBatchArgumentsAreRejected(): void
    {
        $script = $this->root . '/hook.sh';
        file_put_contents($script, "#!/usr/bin/env bash\nexit 0\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unclosed quote in batch arguments');

        $this->executor->execute(BackupCommandTypes::BATCH->value, $script, true, '"unfinished');
    }

    public function testPhpOptionRunsShopwareConsoleCommand(): void
    {
        $marker = $this->root . '/console-args';
        file_put_contents(
            $this->root . '/bin/console',
            '<?php file_put_contents(' . var_export($marker, true) . ', implode("|", array_slice($argv, 1)));'
        );

        $this->executor->execute(BackupCommandTypes::PHP->value, 'cache:clear', true);

        static::assertSame('cache:clear|--no-interaction', file_get_contents($marker));
    }

    public function testFailOnErrorThrows(): void
    {
        $script = $this->root . '/fail.sh';
        file_put_contents($script, "#!/usr/bin/env bash\nexit 7\n");

        $this->expectException(ProcessFailedException::class);
        $this->executor->execute(BackupCommandTypes::BATCH->value, $script, true);
    }

    public function testFailureCanBeLoggedAndIgnored(): void
    {
        $script = $this->root . '/fail.sh';
        file_put_contents($script, "#!/usr/bin/env bash\nexit 7\n");
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->root);

        (new BackupCommandExecutor($logger, $kernel))->execute(
            BackupCommandTypes::BATCH->value,
            $script,
            false
        );

        static::assertFileDoesNotExist($this->root . '/unexpected');
    }

    public function testNoneDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->executor->execute(BackupCommandTypes::NONE->value, '', true);
    }

    /**
     * S-02: batch hooks run arbitrary shell code and must not inherit the shop secrets
     * of the worker process.
     */
    public function testBatchScriptDoesNotInheritShopSecrets(): void
    {
        $marker = $this->root . '/batch-env';
        $script = $this->root . '/hook.sh';
        file_put_contents(
            $script,
            "#!/usr/bin/env bash\n"
            . 'printf \'%s|%s|%s|%s\' "${DATABASE_URL:-}" "${MUWA_FACILITY_SECRET:-}" "${MUWA_DB_TEST:-}" "${MUWA_HOOK_KEEP:-}"'
            . ' > ' . escapeshellarg($marker) . "\n"
        );

        $originalDatabaseUrl = getenv('DATABASE_URL');
        $originalFacilitySecret = getenv('MUWA_FACILITY_SECRET');
        putenv('DATABASE_URL=mysql://root:pw@db/shop');
        putenv('MUWA_FACILITY_SECRET=0123456789abcdef0123456789abcdef');
        putenv('MUWA_DB_TEST=leak');
        putenv('MUWA_HOOK_KEEP=kept');

        try {
            $this->executor->execute(BackupCommandTypes::BATCH->value, $script, true);
        } finally {
            putenv($originalDatabaseUrl === false ? 'DATABASE_URL' : 'DATABASE_URL=' . $originalDatabaseUrl);
            putenv($originalFacilitySecret === false ? 'MUWA_FACILITY_SECRET' : 'MUWA_FACILITY_SECRET=' . $originalFacilitySecret);
            putenv('MUWA_DB_TEST');
            putenv('MUWA_HOOK_KEEP');
        }

        static::assertSame('|||kept', file_get_contents($marker));
    }

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeRecursive($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
