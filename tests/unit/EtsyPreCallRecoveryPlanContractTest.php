<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyPreCallRecoveryPlanContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyPreCallRecoveryPlan.php');
    }

    public function testConsumedAuthorizationCannotBeReusedOrMarkSent(): void
    {
        $s=$this->source();
        foreach([
            "'prior_authorization_consumed'=>true",
            "'reuse_prior_authorization'=>false",
            "'new_authorization_required'=>true",
            "'sent_transition_permitted'=>false",
            "'external_attempt_evidence_present'=>false",
            'EtsyOperationLifecycle::NOT_SENT'
        ] as $needle) self::assertStringContainsString($needle,$s);
    }

    public function testRecoveryRequiresCertainNoAttemptState(): void
    {
        $s=$this->source();
        self::assertStringContainsString('attempt_uncertain',$s);
        self::assertStringContainsString("'adapter_invoked'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }

    public function testRecoveryPlanContainsNoExternalOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['->execute(','wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','->insert(','->update(','ExecutionNonceLedger::consume'] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
