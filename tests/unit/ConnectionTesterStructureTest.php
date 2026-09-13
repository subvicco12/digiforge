<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class ConnectionTesterStructureTest extends TestCase
{
    public function testPrintifyTesterIsExplicitReadOnlyAndHardCoded(): void
    {
        $tester = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/ConnectionTester.php');
        $admin = (string) file_get_contents(__DIR__ . '/../../includes/Integrations/Admin.php');

        self::assertSame(1, substr_count($tester, 'wp_remote_get'));
        self::assertStringContainsString('https://api.printify.com/v1/shops.json', $tester);
        self::assertStringContainsString("'redirection' => 0", $tester);
        self::assertStringContainsString("'sslverify' => true", $tester);
        self::assertStringContainsString("'reject_unsafe_urls' => true", $tester);
        self::assertStringNotContainsString('wp_remote_post', $tester);
        self::assertStringNotContainsString('wp_remote_request', $tester);
        self::assertStringNotContainsString('DELETE', $tester);
        self::assertStringContainsString('admin_post_digiforge_integration_test', $admin);
        self::assertStringContainsString('Test Printify connection', $admin);
        self::assertStringContainsString('read-only GET request', $admin);
    }
}
