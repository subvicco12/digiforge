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
    public function testDashboardSurfacesIntegratedProductionJourney(): void
    {
        $portal = file_get_contents(dirname(__DIR__, 2) . '/includes/Portal/Portal.php');
        self::assertIsString($portal);
        self::assertStringContainsString('Pending opportunity approvals', $portal);
        self::assertStringContainsString('Product approvals', $portal);
        self::assertStringContainsString('Publish-ready listings', $portal);
        self::assertStringContainsString('Production journey', $portal);
        self::assertStringContainsString('Listing / Publish Approval', $portal);
        self::assertStringContainsString("\$value === null ? 'UNAVAILABLE'", $portal);
        self::assertStringContainsString("\$wpdb->last_error = ''", $portal);
        self::assertStringContainsString("return \$wpdb->last_error !== '' ? null : (int) \$value;", $portal);
        self::assertStringNotContainsString("{ return 0; }", $portal);
        self::assertGreaterThanOrEqual(6, substr_count($portal, "\$wpdb->last_error = ''"));
        self::assertStringContainsString("\$pendingAvailable=is_array(\$pending)&&empty(\$wpdb->last_error)", $portal);
        self::assertStringContainsString("\$alertsReadFailed=!is_array(\$alerts)||!empty(\$wpdb->last_error)", $portal);
        self::assertStringContainsString("\$focusedAlertReadFailed=\$alertFocus>0&&!empty(\$wpdb->last_error)", $portal);
    }
}
