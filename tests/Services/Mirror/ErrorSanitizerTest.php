<?php

declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Services\Mirror;

use Aws\Command;
use Aws\Exception\AwsException;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Services\Mirror\ErrorSanitizer;

class ErrorSanitizerTest extends TestCase
{
    public function testSecretIsMasked(): void
    {
        $message = ErrorSanitizer::sanitize(new \RuntimeException('bad S3CR3T'), 'S3CR3T');

        self::assertStringNotContainsString('S3CR3T', $message);
        self::assertSame('RuntimeException: bad ***', $message);
    }

    public function testEmptySecretIsIgnored(): void
    {
        self::assertSame('RuntimeException: x', ErrorSanitizer::sanitize(new \RuntimeException('x'), ''));
    }

    public function testAwsExceptionUsesCodeAndMessage(): void
    {
        $error = new AwsException('raw', new Command('HeadBucket'), ['code' => 'AccessDenied', 'message' => 'Denied S3CR3T']);

        $message = ErrorSanitizer::sanitize($error, 'S3CR3T');

        self::assertSame('AccessDenied: Denied ***', $message);
    }

    public function testInvalidUtf8IsScrubbedBeforeTruncation(): void
    {
        $message = ErrorSanitizer::sanitize(new \RuntimeException("bad \xC3\x28 bytes \xFF" . str_repeat('a', 5000)));

        self::assertTrue(mb_check_encoding($message, 'UTF-8'));
        self::assertSame(1000, mb_strlen($message));
        self::assertNotFalse(json_encode($message));
        self::assertStringStartsWith('RuntimeException: bad ?( bytes ?', $message);
    }

    public function testMessageIsTruncated(): void
    {
        self::assertSame(1000, mb_strlen(ErrorSanitizer::sanitize(new \RuntimeException(str_repeat('a', 5000)))));
    }
}
