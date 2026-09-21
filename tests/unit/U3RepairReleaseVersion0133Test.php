<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairReleaseVersion0133Test extends TestCase
{
    public function testReleaseVersionAndSchemaRemainExpected(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/digiforge.php');
        self::assertIsString($source);
        self::assertStringContainsString("Version: 1.0.10", $source);
        self::assertStringContainsString("DIGIFORGE_VERSION = '1.0.10'", $source);
        self::assertStringContainsString("DIGIFORGE_DB_VERSION = '14'", $source);
    }
}
