<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\Connector\Printify\{NativeOrderSourceEvidence,ShopBinding,CredentialReference,NativePortContract};
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyControlledTransport,ScopedProviderConnection};
final class NativeOrderSourceEvidenceTest extends WP_UnitTestCase {
 private PrintifyApiApprovalFixture $fixture;
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{$this->fixture->restore();parent::tearDown();}
 private function scope():ShopBinding{$v=PrintifyApiApprovalFixture::checked((new ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));return new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);}
 private function source():array{
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'ORDER_READ',['order_id'=>'source_order']));$a=$this->fixture->approve($w,$p,'printify-api-read');
  $body=['id'=>'source_order','status'=>'pending','line_items'=>[['id'=>'line1','product_id'=>'product1','variant_id'=>9,'quantity'=>2]],'shipments'=>[]];
  $r=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($body)))->executeApiRead($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$r['record']['status']);
  DigiForge\Core\Settings::protectProduction();return [$p,$a,$r];
 }
 public function testActualProtectedProducerJoinsCanonicalIdentityAndImmutableSourceWithoutGrant():void{
  self::assertTrue(class_exists(NativeOrderSourceEvidence::class),'Protected order-source resolver must exist.');
  [$p,$a,$r]=$this->source();$scope=$this->scope();$deny=static function(){throw new RuntimeException('No HTTP during source resolution');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{$v=NativeOrderSourceEvidence::resolve($scope,1,$r['hash']);self::assertNotWPError($v);self::assertSame(NativePortContract::hash([['personalized_pod',$this->fixture->integration,'12','test'],'source_order']),$v['order_identity']);self::assertSame($r['hash'],$v['immutable_source_version']);self::assertSame($a['hash'],$v['authorization_hash']);self::assertGreaterThan(0,$v['producer_audit_id']);self::assertFalse($v['operational_grant_issued']);self::assertFalse($v['independent_checkpoint_verified']);self::assertFalse($v['external_execution_authorized']);self::assertArrayNotHasKey('nonce',$v);self::assertWPError(NativeOrderSourceEvidence::resolve($scope,1,str_repeat('f',64)));}finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
 public static function tampering():array{return [['receipt'],['reservation'],['claim'],['producer_actor'],['source_plan'],['membership'],['session'],['cross_shop']];}
 /** @dataProvider tampering */
 public function testSourceSubstitutionAndLostAuthorityDeny(string $mode):void{
  [$p,$a,$r]=$this->source();$scope=$this->scope();global $wpdb;$audit=DigiForge\Database\Tables::audit_log();
  switch($mode){
   case 'receipt':$key='digiforge_api_read_receipt_'.$r['hash'];$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$key]);break;
   case 'reservation':$wpdb->delete($audit,['event_type'=>'printify_api_read_reserved','object_id'=>$p['hash']]);break;
   case 'claim':$wpdb->update($wpdb->options,['option_value'=>str_repeat('a',64)],['option_name'=>'digiforge_api_read_claim_'.$p['hash']]);break;
   case 'producer_actor':$wpdb->update($audit,['actor_id'=>$this->fixture->reviewer],['event_type'=>'printify_api_read_receipt','object_id'=>$p['hash']]);break;
   case 'source_plan':$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>'digiforge_api_read_plan_'.$p['hash']]);break;
   case 'membership':wp_get_current_user()->add_cap('manage_digiforge_orders',false);break;
   case 'session':WP_Session_Tokens::get_instance(1)->destroy(wp_get_session_token());break;
   case 'cross_shop':$scope=new ShopBinding(3,'standard_pod',$scope->integrationId,$scope->providerShopId,$scope->environment,$scope->revision,$scope->credential,$scope->bindingHash,$scope->expiresAt);break;
  }
  self::assertWPError(NativeOrderSourceEvidence::resolve($scope,1,$r['hash']));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testRenewedApprovalCannotRelabelHistoricalProducerAuthorization():void{
  [$p,$a,$r]=$this->source();$scope=$this->scope();$key='printify-api-read:'.$p['hash'];$review=DigiForge\Security\ReviewedLocalEvidence::class;$old=PrintifyApiApprovalFixture::checked($review::approved('policy','personalized_pod',$key));
  PortalAccessFixture::login($this->fixture->reviewer);$revoked=PrintifyApiApprovalFixture::checked($review::decide('policy','personalized_pod',$key,$old['revision'],$old['proposal_hash'],'REVOKED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError(NativeOrderSourceEvidence::resolve($scope,1,$r['hash']));
  $next=PrintifyApiApprovalFixture::checked($review::propose('policy','personalized_pod',$key,$old['payload'],time(),time()+900,wp_generate_uuid4(),$revoked['revision']));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($review::decide('policy','personalized_pod',$key,$next['revision'],$next['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError(NativeOrderSourceEvidence::resolve($scope,1,$r['hash']));
 }
}
