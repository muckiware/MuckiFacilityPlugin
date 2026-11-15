<?php declare(strict_types=1);

namespace MuckiFacilityPlugin\tests\Core\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\Database\DbIdentifier;

/**
 * S-01: spatie/db-dumper inserts the database name and table names unescaped into a
 * shell command line. The validator must reject everything outside the plain
 * identifier charset before it can reach that sink.
 */
final class DbIdentifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validIdentifierProvider(): array
    {
        return [
            'lowercase' => ['shop'],
            'mixed case' => ['Shop_1'],
            'dollar as allowed by mysql' => ['a$b'],
            'digits only' => ['2026'],
            'max length' => [str_repeat('a', 64)],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
            'hyphen (option or shell syntax)' => ['shop-db'],
            'space (command separator)' => ['shop db'],
            'semicolon (command chaining)' => ['shop; echo pwn #'],
            'backticks (command substitution)' => ['`id`'],
            'command substitution' => ['shop$(touch /tmp/pwn)'],
            'single quotes' => ["shop'"],
            'double quotes' => ['shop"x"'],
            'line break' => ["shop\nrm -rf /"],
            'NUL byte' => ["shop\0"],
            'leading option dash' => ['-shop'],
            'pipe' => ['shop|id'],
            'ampersand' => ['shop&id'],
            'dollar parentheses with spaces' => ['shop $(id)'],
        ];
    }

    #[DataProvider('validIdentifierProvider')]
    public function testValidIdentifiersPass(string $identifier): void
    {
        self::assertTrue(DbIdentifier::isValid($identifier));
        DbIdentifier::assertValid($identifier, 'Database name');
        self::assertTrue(DbIdentifier::isValid($identifier), 'assertValid must not alter the value');
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function testInvalidIdentifiersAreRejected(string $identifier): void
    {
        self::assertFalse(DbIdentifier::isValid($identifier));

        $this->expectException(\InvalidArgumentException::class);
        DbIdentifier::assertValid($identifier, 'Database name');
    }

    public function testExceptionNamesTheLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name');

        DbIdentifier::assertValid('a;b', 'Table name');
    }
}
