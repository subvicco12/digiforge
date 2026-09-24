<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cross-boundary Etsy Completion certification contract.
 *
 * This suite intentionally performs no network request. It certifies that the
 * independently tested Etsy boundaries remain wired around the same fail-closed
 * lifecycle before any production activation is considered.
 */
final class EtsyCompletionE2ECertificationContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function testDraftExecutionPersistsEvidenceAndNeverPublishes(): void
    {
        $s=$this->source('includes/Listings/EtsyControlledDraftExecutionCoordinator.php');
        foreach (['recordReconciliationEvidence','EtsyLiveTransportInterlock::authorize','EtsyControlledHttpExecutor','EtsyAttemptLifecycleService',"'publish_permitted'=>false","'automatic_retry_permitted'=>false"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
    }

    public function testUnknownOutcomeRequiresReadOnlyReconciliationBeforeResolution(): void
    {
        $workflow=$this->source('includes/Listings/EtsyReconciliationWorkflow.php');
        $lookup=$this->source('includes/Listings/EtsyReconciliationLookupExecutor.php');
        foreach (['EtsyOperationLifecycle::UNKNOWN','EtsyOperationLifecycle::RECONCILIATION','EtsyOperationLifecycle::RECONCILED','reconciliation_evidence'] as $needle) self::assertStringContainsString($needle,$workflow);
        foreach (["'method']??'')!=='GET'","'mutation_performed'=>false","'external_retry_performed'=>false","'automatic_retry_performed'=>false",'EtsyControlledHttpExecutor'] as $needle) self::assertStringContainsString($needle,$lookup);
        foreach (['wp_remote_','curl_','CredentialVault','EtsyScopedCredentialRetriever'] as $needle) self::assertStringNotContainsString($needle,$lookup);
    }

    public function testWebhookOrderLifecycleCannotAuthorizeFulfillment(): void
    {
        $intake=$this->source('includes/Listings/EtsyWebhookIntake.php');
        $orders=$this->source('includes/Listings/EtsyOrderWebhookLifecycle.php');
        self::assertLessThan(strpos($intake,'->claim('),strpos($intake,'EtsyWebhookVerifier::verify'));
        self::assertLessThan(strpos($intake,'EtsyOrderWebhookLifecycle'),strpos($intake,'->claim('));
        self::assertStringContainsString("'fulfillment_authorized'=>false",$orders);
        foreach (['wp_remote_','curl_exec(','CredentialVault','printify'] as $needle) self::assertStringNotContainsString($needle,$orders);
    }

    public function testPublishBoundaryRemainsLocalOnlyAndRuntimeLocked(): void
    {
        $s=$this->source('includes/Listings/EtsyPublishAuthorizationGate.php');
        foreach (["Settings::get('stop_all',true)!==false","Settings::is_enabled('etsy_publish')","'network_request_permitted'=>false","'etsy_api_invoked'=>false","'external_execution_performed'=>false"] as $needle) self::assertStringContainsString($needle,$s);
        foreach (['wp_remote_','curl_exec(','EtsyScopedCredentialRetriever','CredentialVault'] as $needle) self::assertStringNotContainsString($needle,$s);
    }

    public function testSharedHttpBoundaryKeepsMultipartValidationBeforeSecrets(): void
    {
        $s=$this->source('includes/Listings/EtsyControlledHttpExecutor.php');
        $read=strpos($s,'$multipartBytes=file_get_contents($asset)');
        $interlock=strpos($s,'EtsyLiveTransportInterlock::authorize($prepared)');
        $retrieve=strpos($s,'EtsyScopedCredentialRetriever())->retrieve');
        self::assertNotFalse($read); self::assertNotFalse($interlock); self::assertNotFalse($retrieve);
        self::assertLessThan($interlock,$read);
        self::assertLessThan($retrieve,$interlock);
        self::assertSame(1,substr_count($s,'wp_remote_request('));
    }
}
