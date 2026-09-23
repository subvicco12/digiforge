<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyAdapterInvocationPlanContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAdapterInvocationPlan.php');
    }

    public function testPlanRequiresValidatedHandoffAndNotSentOperation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ETSY_ADAPTER_HANDOFF_VALIDATED'",$source);
        self::assertStringContainsString('EtsyOperationLifecycle::NOT_SENT',$source);
        self::assertStringContainsString('operation_mismatch',$source);
        self::assertStringContainsString('operation_not_sendable',$source);
    }

    public function testPlanRebindsAuthorizationAndEvidenceToOperation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("['authorization_hash','evidence_hash']",$source);
        self::assertStringContainsString('hash_equals($right,$left)',$source);
        self::assertStringContainsString('binding_mismatch',$source);
    }

    public function testPlanningCannotClaimSentOrExternalExecution(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'sent_transition_requires_external_attempt'=>true",$source);
        self::assertStringContainsString("'ledger_state_change_permitted'=>false",$source);
        self::assertStringContainsString("'adapter_invoked'=>false",$source);
        self::assertStringContainsString("'external_execution_performed'=>false",$source);
    }

    public function testPlanContainsNoExecutionPrimitive(): void
    {
        $source=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com','->transition('] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
