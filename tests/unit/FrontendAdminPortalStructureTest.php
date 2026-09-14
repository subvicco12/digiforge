<?php

declare(strict_types=1);

namespace DigiForge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendAdminPortalStructureTest extends TestCase
{
    public function testPortalIsRegisteredAndPreservesApprovalBoundaries(): void
    {
        $plugin = file_get_contents(__DIR__ . '/../../includes/Core/Plugin.php');
        $portal = file_get_contents(__DIR__ . '/../../includes/Portal/Portal.php');

        self::assertIsString($plugin);
        self::assertIsString($portal);
        self::assertStringContainsString('new \\DigiForge\\Portal\\Portal()', $plugin);
        self::assertStringContainsString("digiforge_admin_portal", $portal);
        self::assertStringContainsString('check_admin_referer', $portal);
        self::assertStringContainsString("current_user_can('manage_digiforge')", $portal);
        self::assertStringContainsString('ResearchRepository())->review', $portal);
        self::assertStringContainsString('ExecutionEngine())->develop', $portal);
        self::assertStringContainsString('value="APPROVED"', $portal);
        self::assertStringContainsString('value="REJECTED"', $portal);
        self::assertStringContainsString("Credentials are never displayed", $portal);
        self::assertStringContainsString("Logger::isCredentialKey", $portal);
        self::assertStringNotContainsString('CredentialVault::decrypt', $portal);
        self::assertStringNotContainsString('wp_remote_post', $portal);
        self::assertStringNotContainsString('wp_remote_get', $portal);
    }
}
