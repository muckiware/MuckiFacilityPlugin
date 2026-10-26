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
