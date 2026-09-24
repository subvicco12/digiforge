<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyControlledDraftExecutionCoordinatorContractTest extends TestCase
{
 public function testCoordinatorUsesCertifiedBoundariesAndKeepsPublishDisabled():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledDraftExecutionCoordinator.php');foreach(['EtsyDraftOperationPipeline','EtsyMultipartTransportBinder::bind','EtsyLiveTransportInterlock::authorize','EtsyControlledHttpExecutor','EtsyAttemptLifecycleService','automatic_retry_permitted','publish_permitted'] as $n)self::assertStringContainsString($n,$s);self::assertStringContainsString("'publish_permitted'=>false",$s);}
 public function testNoIndependentHttpCredentialOrPublishBoundaryIsIntroduced():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyControlledDraftExecutionCoordinator.php');foreach(['wp_remote_','CredentialVault','access_token','etsy_publish','PUBLISHED'] as $n)self::assertStringNotContainsString($n,$s);}
}
