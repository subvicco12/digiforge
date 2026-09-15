<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class U3RepairReleaseVersionTest extends TestCase
{
    public function testRepairReleaseUsesVersion0131(): void
    {
        $source = file_get_contents(__DIR__ . '/../../digiforge.php');
        self::assertIsString($source);
        self::assertStringContainsString('Version: 0.13.1', $source);
        self::assertStringContainsString("const DIGIFORGE_VERSION = '0.13.1';", $source);
        self::assertStringContainsString("const DIGIFORGE_DB_VERSION = '13';", $source);
    }
}
