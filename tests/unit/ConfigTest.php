<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use DigiForge\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testAutomationIsFailClosedByDefault(): void
    {
        $defaults = Config::default_settings();

        self::assertTrue($defaults['stop_all']);
        self::assertFalse($defaults['automation_armed']);

        foreach (Config::SWITCHES as $switch) {
            if ($switch !== 'stop_all') {
                self::assertFalse($defaults[$switch]);
            }
        }
    }

    public function testSettingsRegistryRejectsUnknownAndCoercedValues(): void
    {
        self::assertFalse(Config::allowed_setting('unknown'));
        self::assertNull(Config::setting_type('unknown'));
        self::assertFalse(Config::valid_value('stop_all', 1));
        self::assertFalse(Config::valid_value('stop_all', 'true'));
        self::assertTrue(Config::valid_value('stop_all', true));
    }

    public function testAutomationArmingIsNotOrdinarilyWritable(): void
    {
        self::assertFalse(Config::writable_setting('automation_armed'));
        self::assertTrue(Config::writable_setting('stop_all'));
    }
}
