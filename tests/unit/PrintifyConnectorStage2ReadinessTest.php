<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{AcceptancePolicy,HostCompatibility};
use PHPUnit\Framework\TestCase;
final class PrintifyConnectorStage2ReadinessTest extends TestCase {
 private function facts():array{return ['method'=>'GET','shop_key'=>'personalized_pod','integration_id'=>3,'provider_shop_id'=>'28930593','environment'=>'production','stop_all'=>false,'external_lock'=>false,'native_provider_enabled'=>true,'binding_reviewed'=>true,'credential_association_verified'=>true,'installation_approved'=>true,'live_get_approved'=>true];}
 public function testBothLocksRemainAbsoluteRegardlessOfOtherApprovals():void {
  foreach(['stop_all','external_lock'] as $lock){$f=$this->facts();$f[$lock]=true;$r=AcceptancePolicy::assess($f);self::assertContains(strtoupper($lock),$r['blockers']);self::assertFalse($r['execution_authorized']);self::assertFalse($r['eligible_for_controlled_review']);}
 }
 public function testUnknownMissingAndManipulatedEvidenceDenies():void {
  foreach(array_keys($this->facts()) as $field){$f=$this->facts();unset($f[$field]);self::assertFalse(AcceptancePolicy::assess($f)['eligible_for_controlled_review'],$field);}
  foreach(['method'=>'POST','shop_key'=>'standard_pod','integration_id'=>4,'provider_shop_id'=>'28925875','environment'=>'test','stop_all'=>'false','external_lock'=>0,'native_provider_enabled'=>false,'binding_reviewed'=>false,'credential_association_verified'=>false,'installation_approved'=>false,'live_get_approved'=>false] as $key=>$value){$f=$this->facts();$f[$key]=$value;self::assertFalse(AcceptancePolicy::assess($f)['eligible_for_controlled_review'],$key);}
  $f=$this->facts();$f['token']='NEVER RETURN';$r=AcceptancePolicy::assess($f);self::assertFalse($r['eligible_for_controlled_review']);self::assertStringNotContainsString('NEVER RETURN',json_encode($r));
 }
 public function testAssessmentCannotGrantExecutionEvenWhenEvidenceIsComplete():void {
  $r=AcceptancePolicy::assess($this->facts());self::assertTrue($r['eligible_for_controlled_review']);self::assertFalse($r['execution_authorized']);self::assertSame([], $r['blockers']);self::assertFalse(AcceptancePolicy::assess([])['execution_authorized']);
 }
 public function testCurrentMainHostReportsMissingNativeDependenciesWithoutNetworkOrSecrets():void {
  $r=HostCompatibility::inspect();self::assertSame('1.0.0',$r['interface_version']);self::assertFalse($r['execution_authorized']);self::assertContains('DigiForge\\POD\\ScopedProviderConnection::verified',$r['missing_contracts']);self::assertFalse($r['native_contracts_available']);self::assertFalse($r['upload_ownership_available']);
 }
}
