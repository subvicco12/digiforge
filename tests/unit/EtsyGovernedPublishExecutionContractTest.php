<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EtsyGovernedPublishExecutionContractTest extends TestCase
{
    private function source(string $p):string{return (string)file_get_contents(dirname(__DIR__,2).'/'.$p);}
    public function testPublishPlannerIsSinglePurposeAndNetworkDisabled():void
    {
        $s=$this->source('includes/Listings/EtsyPublishListingOperation.php');
        foreach(["'PUBLISH_LISTING'","'PATCH'","['state'=>'active']","'network_request_permitted'=>false","'publish_permitted'=>true"] as $n)self::assertStringContainsString($n,$s);
        foreach(['wp_remote_','curl_','access_token','refresh_token'] as $n)self::assertStringNotContainsString($n,$s);
    }
    public function testPublishInterlockRequiresEffectivePublishCapability():void
    {
        $s=$this->source('includes/Listings/EtsyPublishLiveTransportInterlock.php');
        foreach(["Settings::is_enabled('etsy_publish')!==true","Settings::safety_locked()","'PATCH'"] as $n)self::assertStringContainsString($n,$s);
    }
    public function testControllerRequiresApprovedBoundScopeAndTerminalReplayProtection():void
    {
        $s=$this->source('includes/REST/EtsyPublishExecutionController.php');
        foreach(["'/etsy/publish-listing'","'APPROVED_INTENT'","'APPROVED'","confirmedCreateForScope","confirmedPublishForScope","'ETSY_PUBLISH_LISTING'","'PUBLISH_LISTING'","ETSY_PUBLISH_ALREADY_ATTEMPTED","retry_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);
    }
    public function testPreparationAndHttpPlanSupportOnlyTheGovernedPublishShape():void
    {
        $prep=$this->source('includes/Listings/EtsyOperationPreparationService.php');
        self::assertStringContainsString("'PUBLISH_LISTING'",$prep);
        self::assertStringContainsString("'ETSY_PUBLISH_LISTING'",$prep);
        $http=$this->source('includes/Listings/EtsyHttpRequestPlan.php');
        self::assertStringContainsString("'PATCH'",$http);
    }
    public function testControllerChecksIdempotencyBeforeFreshAuthorization():void
    {
        $s=$this->source('includes/REST/EtsyPublishExecutionController.php');
        $lookup=strpos($s,'$existing=$ops->byKey');
        $issue=strpos($s,'ExecutionAuthorization::issue');
        self::assertNotFalse($lookup);self::assertNotFalse($issue);self::assertLessThan($issue,$lookup);
        self::assertStringContainsString("'retry_permitted'=>false",$s);
    }
    public function testCoordinatorPersistsEvidenceAndKeepsAutomaticRetryDisabled():void
    {
        $s=$this->source('includes/Listings/EtsyControlledPublishExecutionCoordinator.php');
        foreach(['recordReconciliationEvidence','EtsyPublishLiveTransportInterlock::authorize','EtsyControlledHttpExecutor','EtsyAttemptLifecycleService',"automatic_retry_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);
    }
    public function testDraftPlannerStillForbidsPublishState():void
    {
        $s=$this->source('includes/Listings/EtsyDraftListingOperations.php');
        self::assertStringContainsString("'state','is_published','published','active'",$s);
        self::assertStringContainsString("'publish_permitted'=>false",$s);
    }
}
