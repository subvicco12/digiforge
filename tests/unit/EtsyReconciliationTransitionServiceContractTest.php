<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyReconciliationTransitionServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationTransitionService.php');
    }

    public function testBoundaryUsesExistingReconciliationPlanAndLifecycle(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyReconciliationPlan::build',$source);
        self::assertStringContainsString('EtsyOperationLifecycle::RECONCILIATION',$source);
    }

    public function testProviderLookupRequiredAndRetryForbidden(): void
    {
        $source=$this->source();
        self::assertStringContainsString("provider_lookup_required'] ?? null) !== true",$source);
        self::assertStringContainsString("external_retry_permitted'] ?? null) !== false",$source);
        self::assertStringContainsString("'external_retry_permitted'=>false",$source);
    }

    public function testBoundaryContainsNoExternalPrimitive(): void
    {
        $source=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
