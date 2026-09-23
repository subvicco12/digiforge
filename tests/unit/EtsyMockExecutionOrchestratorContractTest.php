<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyMockExecutionOrchestratorContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyMockExecutionOrchestrator.php');
    }

    public function testOrchestrationUsesCertifiedBoundariesInOrder(): void
    {
        $s=$this->source();
        foreach(['EtsyConsumedPermitHandoff::validate','EtsyAdapterInvocationPlan::build','EtsyHttpRequestPlan::build','->execute('] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testMockPathCannotBecomeSentOrExternal(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'sent_transition_permitted'=>false",$s);
        self::assertStringContainsString("'ledger_state_change_permitted'=>false",$s);
        self::assertStringContainsString("'external_attempt_evidence_available'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
        self::assertStringContainsString("['network_request_permitted']??null)!==false",$s);
    }

    public function testOrchestratorContainsNoNetworkOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','EtsySentTransitionService','EtsyExternalAttemptEvidence::validate'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
