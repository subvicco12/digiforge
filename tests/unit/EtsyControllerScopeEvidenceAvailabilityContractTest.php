<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyControllerScopeEvidenceAvailabilityContractTest extends TestCase{
 public function testControlledEndpointsSeparateScopeQueryFailureFromApprovalDenial():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  self::assertGreaterThanOrEqual(9,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(6,substr_count($s,"'digiforge_etsy_scope_evidence_unavailable'"));
  self::assertGreaterThanOrEqual(6,substr_count($s,"'status'=>503"));
  foreach(["digiforge_etsy_image_approval","digiforge_etsy_file_approval","digiforge_etsy_runtime_intent","digiforge_etsy_runtime_package"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testPublishEndpointSeparatesScopeQueryFailureFromExistingDenials():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyPublishExecutionController.php');
  self::assertGreaterThanOrEqual(3,substr_count($s,"\$wpdb->last_error=''"));
  self::assertGreaterThanOrEqual(3,substr_count($s,"'evidence_unavailable'"));
  foreach(["self::error('intent'","self::error('package'","self::error('listing'"] as $n)self::assertStringContainsString($n,$s);
 }
 public function testThisSliceDoesNotAuthorizeOrPerformExternalWork():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/EtsyControlledExecutionController.php');
  self::assertStringContainsString("'publish_permitted'=>false",$s);
 }
}
