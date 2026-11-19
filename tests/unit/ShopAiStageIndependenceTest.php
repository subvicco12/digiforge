<?php
declare(strict_types=1);

use DigiForge\AI\ShopAiPlan;
use PHPUnit\Framework\TestCase;

final class ShopAiStageIndependenceTest extends TestCase
{
    public function testExhaustedResearchCannotBlockAvailableDevelopmentOrListingQuota(): void
    {
        $projection = ShopAiPlan::evaluate([
            'shop_key' => 'digital',
            'stages' => ['research' => ['limit' => 100], 'develop' => ['limit' => 25], 'listing_prepare' => ['limit' => 15]],
        ], ['month' => ['research' => ['count' => 100], 'develop' => ['count' => 3]]]);

        self::assertTrue($projection['quantity_ceiling_reached']);
        self::assertFalse(ShopAiPlan::preflight($projection, 'research', 1, 0)['execution_allowed']);
        foreach (['develop', 'listing_prepare'] as $stage) {
            $preflight = ShopAiPlan::preflight($projection, $stage, 1, 0);
            self::assertTrue($preflight['execution_allowed'], $stage . ' has its own available quota.');
            self::assertFalse($preflight['approval_required']);
            self::assertFalse($preflight['external_execution_performed']);
        }
    }

    public function testIndependentStageStillCannotOverrideAnyParentMonetaryCeiling(): void
    {
        foreach (['run', 'day', 'month'] as $period) {
            $projection = ShopAiPlan::evaluate([
                'shop_key' => 'digital', 'budgets' => [$period => 1],
                'stages' => ['research' => ['limit' => 1], 'develop' => ['limit' => 5]],
            ], ['month' => ['research' => ['count' => 1]], 'costs' => [$period => 1]]);
            $preflight = ShopAiPlan::preflight($projection, 'develop', 1, 0);
            self::assertFalse($preflight['execution_allowed']);
            self::assertTrue($preflight['approval_required']);
        }
    }

    public function testExplicitRunContextDenialStillBlocksAvailableStage(): void
    {
        $projection = ShopAiPlan::evaluate(['shop_key' => 'digital', 'stages' => ['develop' => ['limit' => 5]]]);
        $projection['execution_allowed'] = false;
        $preflight = ShopAiPlan::preflight($projection, 'develop', 1, 0);
        self::assertFalse($preflight['execution_allowed']);
        self::assertContains('existing_ceiling_reached', $preflight['reasons']);
    }
}
