<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class ProductionCommandCenterStructureTest extends TestCase
{
    public function testCommandCenterIsObservationOnly(): void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Portal/ProductionCommandCenter.php');
        self::assertIsString($s);
        self::assertStringNotContainsString('wp_remote_', $s);
        self::assertStringNotContainsString('$wpdb->insert', $s);
        self::assertStringNotContainsString('$wpdb->update', $s);
        self::assertStringNotContainsString('$wpdb->delete', $s);
        self::assertStringNotContainsString('Settings::set', $s);
        self::assertStringContainsString('WHAT NEEDS MY DECISION?', $s);
        self::assertStringContainsString('External execution:', $s);
        self::assertStringContainsString('Ready for product review', $s);
        self::assertStringContainsString('Ready to publish', $s);
        self::assertStringContainsString('Orders requiring attention', $s);
        self::assertStringContainsString('Integration health', $s);
    }

    public function testPortalRegistersCommandCenter(): void
    {
        $s=file_get_contents(dirname(__DIR__,2).'/includes/Core/Plugin.php');
        self::assertIsString($s);
        self::assertStringContainsString('ProductionCommandCenter())->register()', $s);
    }

    public function testResponsivePortalUiIsActuallyLoaded(): void
    {
        $portal=file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
        $css=file_get_contents(dirname(__DIR__,2).'/assets/portal.css');
        $js=file_get_contents(dirname(__DIR__,2).'/assets/portal-ui.js');
        self::assertIsString($portal); self::assertIsString($css); self::assertIsString($js);
        self::assertStringContainsString("wp_enqueue_script('digiforge-portal-ui'", $portal);
        self::assertStringContainsString('df-mobile-nav-toggle', $css);
        self::assertStringContainsString('is-nav-open', $css);
        self::assertStringContainsString('setOpen(false, false)', $js);
        self::assertStringContainsString("nav.addEventListener('click'", $js);
    }
    public function testGateTwoToThreeAutomationRemainsLocal(): void
    {
        $automation=file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/ListingAutomation.php');
        $gate3=file_get_contents(dirname(__DIR__,2).'/includes/Portal/ListingApprovalInbox.php');
        $plugin=file_get_contents(dirname(__DIR__,2).'/includes/Core/Plugin.php');
        self::assertIsString($automation); self::assertIsString($gate3); self::assertIsString($plugin);
        self::assertStringContainsString("u3_product_approved", $automation);
        self::assertStringContainsString("LISTING_REVIEW_REQUIRED", $automation);
        self::assertStringContainsString("recommended_price_amount", $automation);
        self::assertStringNotContainsString('wp_remote_', $automation);
        self::assertStringContainsString("'state' => (string) ($intent['state'] ?? 'BLOCKED')", $gate3);
        self::assertStringContainsString("'publish_authorized' => false", $gate3);
        self::assertStringContainsString("'etsy_api_invoked' => false", $gate3);
        self::assertStringNotContainsString('wp_remote_', $gate3);
        self::assertStringContainsString('ListingAutomation())->register()', $plugin);
        self::assertStringContainsString('ListingApprovalInbox())->register()', $plugin);
    }
}
