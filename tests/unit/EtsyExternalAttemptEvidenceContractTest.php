<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyExternalAttemptEvidenceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyExternalAttemptEvidence.php');
    }

    public function testEvidenceRequiresPlannedInvocationAndMatchingOperation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ETSY_ADAPTER_INVOCATION_PLANNED'",$source);
        self::assertStringContainsString('operation_mismatch',$source);
    }

    public function testSentEligibilityRequiresActualAttemptEvidence(): void
    {
        $source=$this->source();
        self::assertStringContainsString("attempt['adapter_invoked'] ?? null) !== true",$source);
        self::assertStringContainsString("attempt['external_request_attempted'] ?? null) !== true",$source);
        self::assertStringContainsString("'sent_transition_eligible'=>true",$source);
        self::assertStringContainsString("'sent_transition_performed'=>false",$source);
    }

    public function testEvidenceContractCannotMutateLedgerOrInvokeAdapter(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ledger_state_change_permitted'=>false",$source);
        foreach(['->execute(','->transition(','wp_remote_','curl_exec(','api.etsy','etsy.com'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
