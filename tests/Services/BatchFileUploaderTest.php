<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services;

use MuckiFacilityPlugin\Services\BatchFileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

final class BatchFileUploaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/muwa-batch-upload-' . bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectDir);
    }

    public function testUploadsShellScriptToPrivateBatchDirectory(): void
    {
        $uploader = new BatchFileUploader($this->kernel());
        $file = $this->uploadedFile('nightly.sh', "#!/bin/bash\necho first\n");

        $path = $uploader->upload($file);

        $expected = $this->projectDir . '/files/private/mucki-facility/batch/nightly.sh';
        self::assertSame($expected, $path);
        self::assertFileExists($expected);
        self::assertSame("#!/bin/bash\necho first\n", file_get_contents($expected));
    }

    public function testOverwritesExistingFileWithSameName(): void
    {
        $uploader = new BatchFileUploader($this->kernel());
        $uploader->upload($this->uploadedFile('nightly.sh', "old\n"));

        $path = $uploader->upload($this->uploadedFile('nightly.sh', "new\n"));

        self::assertSame("new\n", file_get_contents($path));
    }

    public function testRejectsFilesWithoutShellScriptExtension(): void
    {
        $uploader = new BatchFileUploader($this->kernel());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only .sh files can be uploaded');

        $uploader->upload($this->uploadedFile('nightly.txt', "echo no\n"));
    }

    private function uploadedFile(string $name, string $content): UploadedFile
    {
        $path = tempnam($this->projectDir, 'upload-');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/x-shellscript', null, true);
    }

    private function kernel(): KernelInterface
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        return $kernel;
    }
}
