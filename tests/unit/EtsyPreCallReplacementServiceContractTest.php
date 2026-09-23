<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyPreCallReplacementServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPreCallReplacementService.php');
    }

    public function testReplacementRequiresRecoveryAndFreshBindings(): void
    {
        $s=$this->source();
        foreach(['EtsyPreCallRecoveryPlan::build','authorization_reuse','evidence_reuse','idempotency_reuse','byKey','createFromPayload','fresh_operation_required'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testReplacementIsFreshNotSentAndRequiresNewNonce(): void
    {
        $s=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::NOT_SENT',$s);
        self::assertStringContainsString("'new_nonce_required'=>true",$s);
        self::assertStringContainsString("'source_operation_superseded_for_execution'=>true",$s);
        self::assertStringContainsString("'reuse_prior_authorization'=>false",$s);
        self::assertStringContainsString("'reuse_prior_idempotency_key'=>false",$s);
    }

    public function testServiceCannotInvokeAdapterOrNetwork(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','->execute(','EtsySentTransitionService'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
        self::assertStringContainsString("'adapter_invoked'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }
}
