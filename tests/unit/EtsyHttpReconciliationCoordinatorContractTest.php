<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyHttpReconciliationCoordinatorContractTest extends TestCase
{
    public function testCoordinatorConnectsUnknownAttemptToExistingReconciliationServices():void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyHttpReconciliationCoordinator.php');
        foreach(['ETSY_HTTP_LIFECYCLE_RECORDED','reconciliation_required','automatic_retry_permitted','EtsyOperationLifecycle::UNKNOWN','EtsyReconciliationTransitionService','EtsyReconciliationResultService','provider_lookup_required','external_retry_permitted','network_request_permitted'] as $n) self::assertStringContainsString($n,$s);
        foreach(['wp_remote_','curl_exec(','CredentialVault','access_token','ETSY_LIVE_TRANSPORT_AUTHORIZED'] as $n) self::assertStringNotContainsString($n,$s);
    }

    public function testCoordinatorNeverTurnsReconciliationIntoAutomaticRetry():void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyHttpReconciliationCoordinator.php');
        self::assertStringContainsString("'automatic_retry_permitted'=>false",$s);
        self::assertStringContainsString("'external_retry_permitted'=>false",$s);
        self::assertStringContainsString("'external_execution_performed'=>false",$s);
    }
}
