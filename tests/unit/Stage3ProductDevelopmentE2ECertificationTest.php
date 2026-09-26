<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Stage 3 Product Development end-to-end boundary certificate.
 *
 * This is a source-contract test: it proves that Stage 3 can progress through
 * the local Product Factory/listing-preparation path without silently enabling
 * any later external capability.
 */
final class Stage3ProductDevelopmentE2ECertificationTest extends TestCase
{
    public function testStage3RuntimePathIsPresentAndLaterCapabilitiesRemainIsolated(): void
    {
        $root = dirname(__DIR__, 2);

        $launch = (string) file_get_contents($root . '/includes/REST/LaunchController.php');
        foreach ([
            '/launch/candidates/(?P<id>\d+)/build-product',
            '/launch/product-versions/(?P<id>\d+)/review',
            '/launch/product-versions/(?P<id>\d+)/prepare-listing',
            "'product' => 'generated assets and QA stop at PRODUCT_REVIEW_REQUIRED until explicit review'",
            "'listing_publish' => 'listing preparation stops at LISTING_REVIEW_REQUIRED until explicit Gate 3 review'",
        ] as $contract) {
            self::assertStringContainsString($contract, $launch);
        }

        $review = (string) file_get_contents($root . '/includes/ProductFactory/ProductReview.php');
        foreach ([
            "'u3_product_approved'",
            "'external_actions' => false",
            "'next_action' => 'Listing production may begin in the next stage. Etsy draft/publish remains blocked.'",
        ] as $contract) {
            self::assertStringContainsString($contract, $review);
        }
        foreach (['wp_remote_post', 'wp_remote_get'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $review);
        }

        $listing = (string) file_get_contents($root . '/includes/Listings/ListingFactory.php');
        foreach ([
            "'workflow_status' => 'LISTING_REVIEW_REQUIRED'",
            "'listing_approval_required' => true",
            "'draft_package_created' => false",
            "'etsy_api_invoked' => false",
            "'external_actions_performed' => false",
        ] as $contract) {
            self::assertStringContainsString($contract, $listing);
        }
        foreach (['wp_remote_post', 'wp_remote_get'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $listing);
        }

        $automation = (string) file_get_contents($root . '/includes/ProductFactory/ApprovalAutomation.php');
        self::assertStringContainsString("'external_actions'=>false", $automation);
        foreach (['wp_remote_post', 'wp_remote_get'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $automation);
        }

        $portal = (string) file_get_contents($root . '/includes/Portal/Portal.php');
        foreach ([
            "'listings' => ['label' => 'Listings & Etsy'",
            'Automated',
            'Product Factory + QA',
            'Listing Package + Validation',
            'Gate 3',
            'Listing / Publish Approval',
        ] as $contract) {
            self::assertStringContainsString($contract, $portal);
        }
    }

    public function testStage3MustNotAuthorizeLaterExternalSwitches(): void
    {
        $root = dirname(__DIR__, 2);
        $controls = (string) file_get_contents($root . '/includes/Portal/FrontendControls.php');
        $settings = (string) file_get_contents($root . '/includes/Core/Settings.php');

        self::assertStringContainsString('product_development', $controls);
        self::assertStringContainsString('activateProductDevelopment', $controls);
        self::assertStringContainsString('product_development_activation_authorized', $settings);
        self::assertStringContainsString('in_array($switch, [\'research\', \'ai\', \'product_development\', \'etsy_draft\'], true)', $settings);

        foreach (['printify', 'gelato', 'etsy_publish', 'order_automation', 'gst_automation'] as $laterCapability) {
            self::assertStringNotContainsString("Settings::is_enabled('" . $laterCapability . "')", $controls);
        }
    }
}
