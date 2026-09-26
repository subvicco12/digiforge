<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairReleaseVersionTest extends TestCase
{
    public function testRepairReleaseUsesVersion0104(): void
    {
        $source = file_get_contents(__DIR__ . '/../../digiforge.php');
        self::assertIsString($source);
        self::assertStringContainsString('Version: 1.0.53', $source);
        self::assertStringContainsString("const DIGIFORGE_VERSION = '1.0.53';", $source);
        self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '15';", $source);
    }
}
