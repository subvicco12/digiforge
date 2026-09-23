<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyReconciliationPlanContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyReconciliationPlan.php');
    }

    public function testContractIsLocalOnlyAndCannotRetry(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','$wpdb','->insert(','->update(','->transition(','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
        self::assertStringContainsString("'external_retry_permitted' => false",$source);
        self::assertStringContainsString("'adapter_invoked' => false",$source);
        self::assertStringContainsString("'external_execution_performed' => false",$source);
    }

    public function testOnlyUnknownCanBePlannedForReconciliation(): void
    {
        $source=$this->source();
        self::assertStringContainsString('$state !== EtsyOperationLifecycle::UNKNOWN',$source);
        self::assertStringContainsString("'next_state' => EtsyOperationLifecycle::RECONCILIATION",$source);
    }

    public function testPlanCarriesStableLookupIdentity(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'shop_reference' => $shop",$source);
        self::assertStringContainsString("'idempotency_key' => $key",$source);
        self::assertStringContainsString("'request_fingerprint' => $fingerprint",$source);
        self::assertStringContainsString("'provider_lookup_required' => true",$source);
    }

    public function testFingerprintMustBeCanonicalSha256(): void
    {
        self::assertStringContainsString("/^[a-f0-9]{64}$/",$this->source());
    }
}
