<?php
declare(strict_types=1);
namespace DigiForge\Tests;
require_once __DIR__.'/../../includes/Research/TinyFishResearchContract.php';
use PHPUnit\Framework\TestCase;
use DigiForge\Research\TinyFishResearchContract as Contract;
final class TinyFishResearchContractTest extends TestCase
{
 private function adapter(): Contract {return new Contract(str_repeat('s',32));}
 private function scope():array{return ['shop'=>'personalized_pod','environment'=>'sandbox','integration_id'=>7,'provider_shop_id'=>'28930593','account_sha256'=>hash('sha256','synthetic account'),'session_sha256'=>hash('sha256','synthetic session'),'approval_sha256'=>hash('sha256','synthetic proposed approval'),'scope_revision_sha256'=>hash('sha256','synthetic local scope'),'expires_at'=>1900,'max_requests'=>10,'max_session_seconds'=>600,'max_cost_usd_micros'=>20000,'rate_usd_micros_per_minute'=>2000,'rate_evidence_sha256'=>hash('sha256','synthetic quote'),'families'=>['PP-002','PP-011','PP-003','PP-009','PP-013'],'destinations'=>['US','CA','GB','EU','DE','AU','NZ']];}
 public function testContractIsAvailable():void{self::assertTrue(class_exists(Contract::class));}
 public function testOfflinePlanPreservesUnknownsAndNeverGrantsLiveAuthority():void{
  $s=$this->adapter()->start($this->scope(),1000);self::assertSame(20000,$s['reserved_usd_micros']);self::assertFalse($s['live_execution_authorized']);self::assertFalse($s['supplier_certified']);self::assertSame('UNKNOWN',$s['destination_eligibility']['DE']);self::assertSame('UNKNOWN',$s['destination_eligibility']['NZ']);self::assertSame('UNKNOWN',$s['destination_eligibility']['EU']);
 }
 /** @dataProvider deniedRequest */
 public function testNetworkPolicyDeniesMutationsSecretsAndUnscopedTargets(string $method,string $url,string $type,string $body):void{self::assertFalse(Contract::allows($method,$url,$type,$body));}
 public static function deniedRequest():array{return array_map(static fn($v)=>$v,[['POST','https://printify.com/app/products/68/generic-brand/mug','document',''],['GET','https://printify.com/app/store/28930593/orders/1','document',''],['GET','https://printify.com/app/editor/68/1','document',''],['GET','https://printify.com/app/store/settings/name','document',''],['GET','https://printify.com/app/products/68/x?token=secret','document',''],['GET','https://printify.com.evil.test/app/products/68/x','document',''],['GET','https://printify.com@evil.test/app/products/68/x','document',''],['GET','https://printify.com:443/app/products/68/x','document',''],['GET','https://printify.com/app/products/68/../orders','document',''],['GET','https://printify.com/app/products/68/%2e%2e','document',''],['GET','http://printify.com/app/products/68/x','document',''],['GET','https://printify.com/app/products/68/x','websocket',''],['GET','https://printify.com/app/products/68/x','xhr',''],['GET','https://printify.com/app/products/68/x','document','payload'],['DELETE','https://api.browser.tinyfish.ai/session','document','']]);}
 public function testAllowlistedDocumentsAreNotAFreeFormBrowserGoal():void{
  foreach(['https://printify.com/app/products/68/generic-brand/mug-11oz','https://printify.com/app/print-provider/1/spoke-custom-products','https://printify.com/shipping-rates/spoke-custom-products/']as $url)self::assertTrue(Contract::allows('GET',$url,'document',''));
  $this->expectException(\InvalidArgumentException::class);$this->adapter()->step($this->adapter()->start($this->scope(),1000),'CLICK','https://printify.com/app/products/68/generic-brand/mug-11oz',1001);
 }
 public function testResumeBindsSessionAccountShopRevisionAndExpiry():void{
  $c=$this->adapter();$s=$c->step($c->start($this->scope(),1000),'EXTRACT','https://printify.com/app/products/68/generic-brand/mug-11oz',1001);
  self::assertSame(1,$c->resume($s,$this->scope(),1002)['revision']);self::assertCount(1,$s['events']);self::assertSame($s['events'][0]['request_sha256'],$s['completed'][0]);
  foreach(['shop'=>'digital','account_sha256'=>hash('sha256','foreign'),'session_sha256'=>hash('sha256','other session'),'scope_revision_sha256'=>hash('sha256','drift')]as $k=>$v){$scope=$this->scope();$scope[$k]=$v;try{$c->resume($s,$scope,1002);self::fail('Changed scope accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
  foreach([1600,1900]as $now){try{$c->resume($s,$this->scope(),$now);self::fail('Expired session accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
  $this->expectException(\InvalidArgumentException::class);$c->resume($s,$this->scope(),1002,true);
 }
 public function testTamperedOrForeignKeyCheckpointCannotResume():void{
  $s=$this->adapter()->start($this->scope(),1000);$s['request_count']=0;$s['scope']['max_requests']=99;
  $this->expectException(\InvalidArgumentException::class);$this->adapter()->resume($s,$this->scope(),1001);
 }
 public function testCheckpointKeyIsNotSharedAcrossTrustedServices():void{
  $s=$this->adapter()->start($this->scope(),1000);$this->expectException(\InvalidArgumentException::class);(new Contract(str_repeat('t',32)))->resume($s,$this->scope(),1001);
 }
 public function testCostMissingOrAboveExplicitReservationFailsClosed():void{
  $scope=$this->scope();$scope['max_cost_usd_micros']=19999;$this->expectException(\InvalidArgumentException::class);$this->adapter()->start($scope,1000);
 }
 public function testQuotaAndDeduplicationDoNotRestartUnknownWork():void{
  $c=$this->adapter();$scope=$this->scope();$scope['max_requests']=1;$s=$c->step($c->start($scope,1000),'EXTRACT','https://printify.com/app/products/68/generic-brand/mug-11oz',1001);
  $this->expectException(\InvalidArgumentException::class);$c->step($s,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug-11oz',1002);
 }
 public function testStrictFieldsRejectCookieAndGoalSmuggling():void{
  $scope=$this->scope();$scope['cookies']='never accepted';$this->expectException(\InvalidArgumentException::class);$this->adapter()->start($scope,1000);
 }
 public function testObservationIsTypedHashedUnreviewedAndCannotCertifyRights():void{
  $c=$this->adapter();$scope=$this->scope();$s=$c->step($c->start($scope,1000),'EXTRACT','https://printify.com/app/products/68/generic-brand/mug-11oz',1001);
  $facts=['family'=>'PP-002','destination'=>'US','blueprint_id'=>68,'provider_id'=>null,'variant_id'=>null,'width_px'=>null,'height_px'=>null,'dpi'=>null,'bleed_px'=>null,'safe_zone_px'=>null,'price_usd_micros'=>null,'shipping_usd_micros'=>null,'eligible'=>null];
  $r=$c->observation($s,$scope,$facts,1002);self::assertSame('UNKNOWN',$r['facts']['dpi']);self::assertSame('UNKNOWN',$r['mockup_rights']);self::assertFalse($r['supplier_certified']);self::assertSame('OFFLINE_SYNTHETIC_UNREVIEWED',$r['classification']);self::assertSame(64,strlen($r['evidence_sha256']));
  $facts['cookies']='forbidden';$this->expectException(\InvalidArgumentException::class);$c->observation($s,$scope,$facts,1002);
 }
 public function testBudgetScopeAndSessionLimitsRejectMalformedInputs():void{
  foreach(['max_session_seconds'=>0,'max_requests'=>101,'max_cost_usd_micros'=>null,'rate_usd_micros_per_minute'=>0,'families'=>['PP-002','PP-002'],'destinations'=>['us'],'environment'=>'unknown','approval_sha256'=>'','provider_shop_id'=>'0'] as $k=>$v){$s=$this->scope();$s[$k]=$v;try{$this->adapter()->start($s,1000);self::fail('Invalid field accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }
 public function testRateWindowAndMonotonicTimeAreBounded():void{
  $scope=$this->scope();$scope['max_requests']=100;$c=$this->adapter();$s=$c->start($scope,1000);
  for($i=1;$i<=10;$i++)$s=$c->step($s,'EXTRACT','https://printify.com/app/products/'.$i.'/generic-brand/mug',1000+$i);
  $this->expectException(\InvalidArgumentException::class);$c->step($s,'EXTRACT','https://printify.com/app/products/11/generic-brand/mug',1011);
 }
 public function testRepeatedRequestBeforeQuotaCannotBeReplayed():void{
  $c=$this->adapter();$s=$c->step($c->start($this->scope(),1000),'EXTRACT','https://printify.com/app/products/68/generic-brand/mug',1001);
  $this->expectException(\InvalidArgumentException::class);$c->step($s,'EXTRACT','https://printify.com/app/products/68/generic-brand/mug',1002);
 }
 public function testCheckpointSecretCannotBeSerialized():void{
  $this->expectException(\LogicException::class);serialize($this->adapter());
 }

 public function testNavigationCannotBeClaimedAsExtractionEvidence():void{
  $c=$this->adapter();$s=$c->step($c->start($this->scope(),1000),'NAVIGATE','https://printify.com/app/products/68/generic-brand/mug',1001);
  $facts=['family'=>'PP-002','destination'=>'US','blueprint_id'=>68,'provider_id'=>null,'variant_id'=>null,'width_px'=>null,'height_px'=>null,'dpi'=>null,'bleed_px'=>null,'safe_zone_px'=>null,'price_usd_micros'=>null,'shipping_usd_micros'=>null,'eligible'=>null];
  $this->expectException(\InvalidArgumentException::class);$c->observation($s,$this->scope(),$facts,1002);
 }
 public function testExpiryRevocationAndClockRollbackNeverImplyReauthentication():void{
  $c=$this->adapter();$s=$c->start($this->scope(),1000);
  foreach([999,1600,1900] as $now){try{$c->resume($s,$this->scope(),$now);self::fail('Invalid clock accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
 }

 public function testResumeCannotMoveBeforeLastCompletedExtraction():void{
  $c=$this->adapter();$s=$c->step($c->start($this->scope(),1000),'EXTRACT','https://printify.com/app/products/68/generic-brand/mug',1010);
  $this->expectException(\InvalidArgumentException::class);$c->resume($s,$this->scope(),1009);
 }

}
