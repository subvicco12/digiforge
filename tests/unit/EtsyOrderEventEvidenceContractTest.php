<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyOrderEventEvidenceContractTest extends TestCase {
 public function testShippingEvidenceIsPrivacyMinimizedAndNonAuthoritative():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderEventEvidence.php');foreach(['tracking_fingerprint','carrier','authoritative_fulfillment_state',"'fulfillment_authorized'=>false","'external_execution_performed'=>false"] as $n)self::assertStringContainsString($n,$s);self::assertStringNotContainsString("'tracking_code'=>",$s);}
 public function testWebhookLifecycleUsesEvidenceWithoutAuthorizingFulfillment():void{$s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOrderWebhookLifecycle.php');self::assertStringContainsString('EtsyOrderEventEvidence::normalize',$s);self::assertStringContainsString("'fulfillment_authorized'=>false",$s);}
}
