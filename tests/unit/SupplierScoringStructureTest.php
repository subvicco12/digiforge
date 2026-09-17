<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SupplierScoringStructureTest extends TestCase
{
    public function test_scoring_contract_is_provider_neutral_and_demand_first(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/POD/SupplierScoring.php');
        self::assertIsString($source);
        self::assertStringContainsString("'market_opportunity' => 20", $source);
        self::assertStringContainsString("'landed_cost' => 20", $source);
        self::assertStringContainsString("['US','EU']", $source);
        self::assertStringContainsString("EXTERNAL_PROVIDER_SEARCH", $source);
        self::assertStringContainsString("SUPPLIER_SELECTED", $source);
        self::assertStringNotContainsString('wp_remote_', $source);
        self::assertStringNotContainsString('etsy', strtolower($source));
    }
}
