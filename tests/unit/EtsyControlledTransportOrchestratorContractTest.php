<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyControlledTransportOrchestratorContractTest extends TestCase
{
    public function testOrchestratorComposesControlledPreNetworkPath(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledTransportOrchestrator.php');
        foreach(['EtsyConsumedPermitHandoff::validate','EtsyAdapterInvocationPlan::build','EtsyTokenAcquisitionGate::evaluate','EtsyScopedCredentialAccess::authorize','EtsyHttpRequestPlan::build','EtsyCredentialAwareTransport'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        foreach(["'network_request_permitted'=>false","'network_request_attempted'=>false","'sent_transition_permitted'=>false","'ledger_state_change_permitted'=>false","'external_execution_performed'=>false"] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        self::assertStringNotContainsString('EtsyScopedCredentialRetriever',$s);
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','Logger::','error_log('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }

    public function testRequestPlanCarriesIntegrationIdentityWithoutChangingDefaultCallers(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyHttpRequestPlan.php');
        self::assertStringContainsString('int $integrationId=0',$s);
        self::assertStringContainsString("'integration_id'=>\$integrationId",$s);
    }
}
