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
namespace MuckiFacilityPlugin\tests\Core;

use PHPUnit\Framework\TestCase;

use MuckiFacilityPlugin\Core\MysqlDumpOption;

class MysqlDumpOptionTest extends TestCase
{
    private const CONFIG_XML = __DIR__ . '/../../src/Resources/config/config.xml';
    private const ADMIN_DETAIL_PAGE = __DIR__
        . '/../../src/Resources/app/administration/src/module/muwa-backup-repository/page/muwa-database-connection-detail/index.js';

    public function testDefaultsKeepTheBehaviourBeforeOptionsWereSelectable(): void
    {
        static::assertSame([MysqlDumpOption::NO_TABLESPACES], MysqlDumpOption::DEFAULTS);
    }

    /**
     * spatie puts the arguments unescaped into a shell command line.
     */
    public function testArgumentsContainNoShellMetacharacters(): void
    {
        foreach (MysqlDumpOption::cases() as $option) {
            static::assertMatchesRegularExpression('/^--[a-z0-9-]+(=[A-Za-z0-9]+)?$/', $option->getArgument(), $option->name);
        }
    }

    public function testFromValuesDropsUnknownValuesAndDuplicatesInEnumOrder(): void
    {
        static::assertSame(
            [MysqlDumpOption::NO_TABLESPACES, MysqlDumpOption::EVENTS, MysqlDumpOption::HEX_BLOB],
            MysqlDumpOption::fromValues(['hexBlob', 'events', '--routines; rm -rf /', 'events', 'noTablespaces', 42])
        );
        static::assertSame([], MysqlDumpOption::fromValues([]));
    }

    public function testFromInputRejectsUnknownValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MysqlDumpOption::fromInput(['routines', '--routines']);
    }

    public function testFromInputRejectsNonStringValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MysqlDumpOption::fromInput([['routines']]);
    }

    public function testFromInputAcceptsKnownValues(): void
    {
        static::assertSame(
            ['routines', 'setGtidPurgedOff'],
            MysqlDumpOption::toValues(MysqlDumpOption::fromInput(['setGtidPurgedOff', 'routines']))
        );
    }

    /**
     * Settings::getDefaultConnectionDumpOptions() reads one bool per option from config.xml.
     */
    public function testConfigXmlHasABoolSettingWithMatchingDefaultForEveryOption(): void
    {
        $xml = simplexml_load_file(self::CONFIG_XML);
        static::assertNotFalse($xml);

        foreach (MysqlDumpOption::cases() as $option) {
            $name = 'mysqldump' . ucfirst($option->value);
            $fields = $xml->xpath(sprintf('//input-field[name="%s"]', $name));
            static::assertCount(1, $fields ?: [], $name);
            static::assertSame('bool', (string) $fields[0]['type'], $name);
            static::assertSame(
                in_array($option, MysqlDumpOption::DEFAULTS, true) ? 'true' : 'false',
                (string) $fields[0]->defaultValue,
                $name
            );
        }
    }

    public function testAdministrationOffersExactlyTheAllowlist(): void
    {
        $source = (string) file_get_contents(self::ADMIN_DETAIL_PAGE);
        static::assertSame(1, preg_match('/const DUMP_OPTIONS = \[(.*?)\];/s', $source, $matches));
        preg_match_all("/'([A-Za-z0-9]+)'/", $matches[1], $values);

        static::assertSame(MysqlDumpOption::toValues(MysqlDumpOption::cases()), $values[1]);
    }
}
