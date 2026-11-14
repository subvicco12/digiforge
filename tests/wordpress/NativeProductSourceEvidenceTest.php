<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeApprovedPackageFixture.php';
use DigiForge\POD\{PrintifyProductPipeline,PrintifyControlledTransport,ScopedProviderConnection,ProductPackageRepository};
use DigiForge\Connector\Printify\{NativePlanEvidence,ShopBinding,CredentialReference};
final class NativeProductSourceEvidenceTest extends WP_UnitTestCase {
 use NativeApprovedPackageFixture;
 private PrintifyApiApprovalFixture $fixture;private array $fieldTypes;
 protected function setUp():void{parent::setUp();global $wpdb;$this->fieldTypes=$wpdb->field_types;$wpdb->field_types['object_id']='%d';$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{try{$this->fixture->restore();}finally{global $wpdb;$wpdb->field_types=$this->fieldTypes;parent::tearDown();}}
 /** Fault injection must match exactly one hash, regardless of global WP integer metadata. */
 private function producerActor(string $hash,int $actor):void {
  global $wpdb;self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::audit_log(),['actor_id'=>$actor],['event_type'=>'printify_product_dispatch_reserved','object_type'=>'printify_product','object_id'=>$hash],['%d'],['%s','%s','%s']));self::assertSame((string)$actor,$wpdb->get_var($wpdb->prepare('SELECT actor_id FROM '.DigiForge\Database\Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','printify_product_dispatch_reserved','printify_product',$hash)));
 }
 /** @dataProvider producerHashIdentities */
 public function testProducerMutationUsesExactHashEvenForScientificNotationPrefix(string $hash,string $other):void {
  global $wpdb;$table=DigiForge\Database\Tables::audit_log();
  foreach([$hash,$other]as $id)self::assertSame(1,$wpdb->insert($table,['event_type'=>'printify_product_dispatch_reserved','object_type'=>'printify_product','object_id'=>$id,'actor_id'=>1,'context'=>'{}','created_at'=>gmdate('Y-m-d H:i:s')],array_fill(0,6,'%s')));
  $this->producerActor($hash,$this->fixture->reviewer);
  self::assertSame((string)$this->fixture->reviewer,$wpdb->get_var($wpdb->prepare('SELECT actor_id FROM '.$table.' WHERE object_id=%s AND object_type=%s',$hash,'printify_product')));
  self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT actor_id FROM '.$table.' WHERE object_id=%s AND object_type=%s',$other,'printify_product')));
 }
 public static function producerHashIdentities():array{return ['scientific_notation_prefix'=>['1e99'.str_repeat('a',60),'1e88'.str_repeat('b',60)],'alphabetic'=>[str_repeat('a',64),str_repeat('b',64)]];}
 public function testProtectedProductProducerClaimAndImmutableWorkflowJoinRemainNonexecuting():void {
  self::assertTrue(method_exists(PrintifyProductPipeline::class,'receiptSource'),'Protected product producer join is required.');
  $c=new ScopedProviderConnection();$p=PrintifyApiApprovalFixture::checked($c->propose('personalized_pod',['integration_id'=>$this->fixture->integration,'capability'=>'printify.product.prepare','source_sha256'=>hash('sha256','product-source'),'evidence_reference'=>'local://isolated/product-source','observed_at'=>gmdate('c',time()-1)],time(),time()+1800,wp_generate_uuid4(),0));
  PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($c->decide('personalized_pod',$this->fixture->integration,'printify.product.prepare',$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
  $package=$this->approvedNativePackage();$v=PrintifyApiApprovalFixture::checked((new ProductPackageRepository())->read('personalized_pod',$package['package']));$design=$v['record']['commercial_design_metadata']['design_hash'];$d=PrintifyApiApprovalFixture::checked((new DigiForge\Research\DesignFactoryRepository())->read('personalized_pod',$design));$ref=$d['record']['asset']['raster_references'][0]['reference'];$raster=PrintifyApiApprovalFixture::checked((new DigiForge\Research\DesignFactoryRepository())->raster('personalized_pod',$design,$ref));
  $this->fixture->refreshOwnerVerification();$pipeline=new PrintifyProductPipeline();$plan=PrintifyApiApprovalFixture::checked($pipeline->prepare($package['workflow'],$package['package'],$this->fixture->integration,'IMAGE_UPLOAD',['asset_reference'=>$ref]));$auth=$this->fixture->approve($pipeline,$plan,'printify-product');$image=['id'=>'source_image','width'=>2475,'height'=>1155,'size'=>strlen(base64_decode($raster['base64'],true)),'mime_type'=>'image/png','file_name'=>'fixture.png','upload_time'=>'2026-11-09 00:00:00'];
  $r=PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response($image)))->executeProduct($plan['hash'],$auth['hash']));self::assertSame('SUCCEEDED',$r['record']['status']);DigiForge\Core\Settings::protectProduction();
  $binding=PrintifyApiApprovalFixture::checked($c->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$scope=new ShopBinding(2,'personalized_pod',$this->fixture->integration,$binding['external_shop_reference'],$binding['environment'],$binding['review_revision'],new CredentialReference($this->fixture->integration),$binding['binding_hash'],$binding['expires_at']);
  $deny=static function(){throw new RuntimeException('No HTTP during source inspection');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{
   $source=PrintifyApiApprovalFixture::checked($pipeline->receiptSource($r['hash']));self::assertSame($auth['hash'],$source['authorization_hash']);self::assertGreaterThan(0,$source['producer_audit_id']);self::assertSame(1,$source['producer_actor_id']);self::assertNull($source['original_approval_revision']);self::assertArrayNotHasKey('nonce',$source);
   $e=PrintifyApiApprovalFixture::checked(NativePlanEvidence::receipt($scope,1,$r['hash']));self::assertSame($package['package'],$e['package_version_hash']);self::assertSame($package['workflow'],$e['workflow_hash']);self::assertSame($v['record']['artwork_version_hash'],$e['artwork_version_hash']);self::assertSame($design,$e['design_factory_hash']);self::assertFalse($e['native_producer_fence_join_verified']);self::assertFalse($e['external_execution_authorized']);
   global $wpdb;$claim='digiforge_pp_claim_'.hash('sha256',$package['package'].':'.$this->fixture->integration.':'.$plan['record']['request']['fingerprint']);self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>str_repeat('f',64)],['option_name'=>$claim],['%s'],['%s']));self::assertWPError($pipeline->receiptSource($r['hash']));self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>$auth['hash']],['option_name'=>$claim],['%s'],['%s']));
   $audit=DigiForge\Database\Tables::audit_log();$this->producerActor($plan['hash'],$this->fixture->reviewer);self::assertWPError($pipeline->receiptSource($r['hash']));$this->producerActor($plan['hash'],1);self::assertNotWPError($pipeline->receiptSource($r['hash']));
   $foreign=new ShopBinding(3,'standard_pod',$scope->integrationId,$scope->providerShopId,$scope->environment,$scope->revision,$scope->credential,$scope->bindingHash,$scope->expiresAt);self::assertWPError(NativePlanEvidence::receipt($foreign,1,$r['hash']));
   $authKey='digiforge_pp_authorization_'.$auth['hash'];$original=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$authKey));self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$authKey],['%s'],['%s']));self::assertWPError($pipeline->receiptSource($r['hash']));self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>$original],['option_name'=>$authKey],['%s'],['%s']));
   $user=wp_get_current_user();$had=array_key_exists('manage_digiforge_products',$user->caps);$previous=$user->caps['manage_digiforge_products']??false;$user->add_cap('manage_digiforge_products',false);try{self::assertWPError(NativePlanEvidence::receipt($scope,1,$r['hash']));}finally{if($had)$user->add_cap('manage_digiforge_products',$previous);else $user->remove_cap('manage_digiforge_products');}
   $nonceTable=DigiForge\Database\Tables::pod_execution_nonces();self::assertSame(1,$wpdb->update($nonceTable,['consumed_by'=>$this->fixture->reviewer],['authorization_hash'=>$auth['hash']],['%d'],['%s']));self::assertWPError($pipeline->receiptSource($r['hash']));self::assertSame(1,$wpdb->update($nonceTable,['consumed_by'=>1],['authorization_hash'=>$auth['hash']],['%d'],['%s']));
   $reservation=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$audit.' WHERE event_type=%s AND object_id=%s','printify_product_dispatch_reserved',$plan['hash']),ARRAY_A);self::assertSame(1,$wpdb->delete($audit,['id'=>$reservation['id']],['%d']));self::assertWPError(NativePlanEvidence::receipt($scope,1,$r['hash']));self::assertSame(1,$wpdb->insert($audit,$reservation,array_fill(0,count($reservation),'%s')));self::assertNotWPError($pipeline->receiptSource($r['hash']));
   $key='printify-product:'.$plan['hash'];$review=DigiForge\Security\ReviewedLocalEvidence::class;$policy=PrintifyApiApprovalFixture::checked($review::approved('policy','personalized_pod',$key));PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($review::decide('policy','personalized_pod',$key,$policy['revision'],$policy['proposal_hash'],'REVOKED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError($pipeline->receiptSource($r['hash']));self::assertTrue(DigiForge\Core\Settings::safety_locked());

  }finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
}
