<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyConsumedPermitHandoffContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyConsumedPermitHandoff.php');
    }

    public function testHandoffRequiresConsumedPermitAndPreparedOperation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ETSY_OPERATION_PREPARED'",$source);
        self::assertStringContainsString("'ADAPTER_CALL_PERMITTED'",$source);
        self::assertStringContainsString("'nonce_consumed'] ?? null) !== true",$source);
        self::assertStringContainsString('invalid_permit',$source);
    }

    public function testConsumedPermitDoesNotClaimSentOrLedgerMutation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'ledger_state_change_permitted' => false",$source);
        self::assertStringContainsString("'sent_state_claimed' => false",$source);
        self::assertStringContainsString("'adapter_invoked' => false",$source);
        self::assertStringContainsString("'external_execution_performed' => false",$source);
    }

    public function testHandoffBindsAuthorizationAndEvidence(): void
    {
        $source=$this->source();
        self::assertStringContainsString("permit['authorization_hash']",$source);
        self::assertStringContainsString("permit['evidence_hash']",$source);
        self::assertStringContainsString('invalid_binding',$source);
    }

    public function testHandoffRemainsLocalOnly(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','->execute(','->transition('] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
