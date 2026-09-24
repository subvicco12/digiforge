<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyControlledTransportContractTest extends TestCase{
 public function testTransportIsRuntimeLockedScopedAndNoRetry():void{
  $i=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyLiveTransportInterlock.php');
  foreach(['Settings::safety_locked()',"Settings::is_enabled('printify')","Settings::is_enabled('order_automation')",'PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'] as $x)self::assertStringContainsString($x,$i);
  $t=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyControlledTransport.php');
  self::assertSame(1,substr_count($t,'wp_remote_request('));
  foreach(['sleep(','usleep(','wp_schedule_','as_schedule_'] as $x)self::assertStringNotContainsString($x,$t);
  self::assertStringContainsString('PrintifyLiveTransportInterlock::authorize',$t);
  self::assertStringContainsString('PrintifyScopedCredentialRetriever',$t);
  self::assertStringContainsString("network_request_attempted'=>true",$t);
  self::assertStringContainsString("'status'=>'UNKNOWN'",$t);
  self::assertStringContainsString('approved_request_fingerprint',$t);
  self::assertStringContainsString('request_fingerprint',$t);
  self::assertStringContainsString('reconciliation_identity',$t);
 }
 public function testOrderAndProductionRemainSeparateAndOfficialV1OrderEndpointsAreBound():void{
  $r=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyMutationRequest.php');
  self::assertStringContainsString("\$endpoint='/shops/'.\$shop.'/orders.json'",$r);
  self::assertStringContainsString("\$endpoint='/shops/'.\$shop.'/orders/'.\$order.'/send_to_production.json'",$r);
  self::assertStringContainsString("'api_version'=>'v1'",$r);
  self::assertStringContainsString("'v2_preference_preserved'=>true",$r);
  self::assertStringContainsString("'v1_required_for_operation'=>true",$r);
  self::assertStringContainsString("preg_match('/^[A-Za-z0-9_-]{1,160}$/',\$order)",\$r);
 }
 public function testCredentialMaterialNeverLeavesCallbackBoundary():void{
  $c=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/PrintifyScopedCredentialRetriever.php');
  self::assertStringContainsString('CredentialVault::decrypt',$c);
  self::assertStringContainsString('finally',$c);
  self::assertStringNotContainsString("'token'=>",$c);
  self::assertStringNotContainsString('Logger::',$c);
 }
}
