<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyOperationPreparationServiceContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
    }

    public function testPreparationRemainsLocalOnlyAndDoesNotInvokeAdapter(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
        self::assertStringContainsString("'adapter_invoked' => false",$source);
        self::assertStringContainsString("'external_execution_performed' => false",$source);
    }

    public function testPreparationRequiresNotSentLedgerRecordAndPolicy(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::NOT_SENT',$source);
        self::assertStringContainsString('EtsyExecutionPolicy::evaluate',$source);
        self::assertStringContainsString('policy_denied',$source);
    }

    public function testPreparationRevalidatesCurrentApprovedScope(): void
    {
        $source=$this->source();
        self::assertStringContainsString('APPROVED_INTENT',$source);
        self::assertStringContainsString("'state']??'') !== 'APPROVED'",$source);
        self::assertStringContainsString('intent_not_approved',$source);
        self::assertStringContainsString('package_not_approved',$source);
        self::assertStringContainsString('listing_not_approved',$source);
        self::assertStringContainsString('shop_reference',$source);
    }

    public function testPreparationMapsLedgerOperationToControlledAuthorizationAction(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'CREATE_DRAFT'",$source);
        self::assertStringContainsString("'policy_operation' => EtsyExecutionPolicy::OP_DRAFT",$source);
        self::assertStringContainsString("'authorization_action' => 'ETSY_DRAFT_CREATE'",$source);
        self::assertStringContainsString("\$mapping['authorization_action']",$source);
        self::assertStringContainsString('operation_not_supported',$source);
    }

    public function testPreparationBindsApprovedIntentTypeToOperation(): void
    {
        $source=$this->source();
        self::assertStringContainsString("'PREPARE_DRAFT' => in_array(\$operationType, ['DRAFT', 'CREATE_DRAFT'], true)",$source);
        self::assertStringContainsString('intent_operation_mismatch',$source);
        self::assertStringContainsString('intentMatchesOperation($intentType',$source);
        foreach(['PREPARE_MEDIA','PREPARE_INVENTORY','PREPARE_PERSONALIZATION','PREPARE_UPDATE'] as $unsupported) {
            self::assertStringNotContainsString("'".$unsupported."' =>",$source);
        }
    }

    public function testPreparationBindsPersistedEvidenceAuthorizationAndPayload(): void
    {
        $source=$this->source();
        self::assertStringContainsString('evidence_mismatch',$source);
        self::assertStringContainsString('authorization_mismatch',$source);
        self::assertStringContainsString('request_mismatch',$source);
        self::assertStringContainsString('EtsyRequestFingerprint::fromPayload($payload)',$source);
    }

    public function testPreparationUsesConsumedControlledExecutionPermit(): void
    {
        $source=$this->source();
        self::assertStringContainsString('ExecutionOrchestrator::prepare',$source);
        self::assertStringContainsString("'state' => 'ETSY_OPERATION_PREPARED'",$source);
        self::assertStringContainsString("'permit' => \$prepared['permit']",$source);
    }
}
