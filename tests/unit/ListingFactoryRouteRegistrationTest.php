<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ListingFactoryRouteRegistrationTest extends TestCase
{
    private function source(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/REST/LaunchController.php');
        self::assertIsString($source);
        return $source;
    }

    public function testPrepareListingRouteIsActuallyRegistered(): void
    {
        $source = $this->source();
        self::assertStringContainsString("use DigiForge\\Listings\\ListingFactory;", $source);
        self::assertStringContainsString("'/launch/product-versions/(?P<id>\\d+)/prepare-listing'", $source);
        self::assertStringContainsString("'permission_callback' => [\$this, 'canPrepareListing']", $source);
        self::assertStringContainsString("'callback' => [\$this, 'prepareListing']", $source);
    }

    public function testPrepareListingRetainsGate3AndIdempotencyBoundary(): void
    {
        $source = $this->source();
        self::assertStringContainsString("Capabilities::can('manage_digiforge_listings')", $source);
        self::assertStringContainsString("'u3_prepare_listing_' . (int) \$request['id']", $source);
        self::assertStringContainsString("(new ListingFactory())->prepare((int) \$request['id'], \$key)", $source);
    }
}
