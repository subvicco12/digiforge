<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeBusinessMutationFixture.php';
use DigiForge\Connector\Printify\{NativeBusinessMutationAssociation as Association,NativeHeldJournalSchema,NativeHeldJournal,NativeBridge};
use DigiForge\Database\Tables;
final class NativeBusinessMutationAssociationTest extends WP_UnitTestCase {
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $snapshots=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);PortalAccessFixture::reset();DigiForge\Core\Activator::activate();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;foreach([Tables::opportunities(),Tables::product_families(),Tables::products(),Tables::product_versions(),Tables::production_plans(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::asset_specs(),Tables::production_plan_assets()]as $t)$this->snapshots[$t]=$wpdb->get_results('SELECT * FROM '.$t,ARRAY_A);DigiForge\Core\Settings::protectProduction();}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->snapshots as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $r)$wpdb->insert($t,$r,array_fill(0,count($r),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testExactIndependentSourceAssociationDoesNotGrantDispatchOrNativeCounters():void {
  self::assertTrue(class_exists(Association::class),'Core-owned independently reviewed source association producer required.');
  $f=$this->businessMutationFixture($this->fixture);$scope=$f['scope'];$m=$f['mapping_id'];$s=$f['spec_id'];$p=$f['plan_hash'];
  $deny=static function(){throw new RuntimeException('No provider HTTP');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try {
   $source=PrintifyApiApprovalFixture::checked(Association::source($scope,1,$m,$s,$p));
   self::assertWPError(Association::read($scope,1,$m,$s,$p));
   $proposal=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],0,time(),time()+600,wp_generate_uuid4()));
   self::assertSame('PENDING',$proposal['status']);self::assertSame(1,$proposal['version']);
   self::assertWPError(Association::decide($scope,1,$m,$s,$p,1,$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));
   PortalAccessFixture::login($this->fixture->reviewer);
   $review=PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,1,$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));self::assertSame('APPROVED',$review['status']);
   $this->fixture->owner();$a=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));self::assertSame(1,$a['association_version']);self::assertSame(2,$a['review_revision']);self::assertTrue($a['business_package_binding_approved']);self::assertFalse($a['source_approval_cas_verified']);self::assertNull($a['native_product_version']);self::assertFalse($a['mutation_authorized']);self::assertFalse($a['dispatch_permitted']);
   self::assertArrayHasKey('current_source_evidence_hash',$a,'Use the exact already validated current source, never an unverified cached association.');
   $e=PrintifyApiApprovalFixture::checked(DigiForge\Connector\Printify\NativeBusinessMutationEvidence::resolve($scope,1,$m,$s,$p));
   self::assertSame($e['evidence_hash'],$a['current_source_evidence_hash']);
   $wire=PrintifyApiApprovalFixture::checked(Association::uploadDescriptor($scope,1,$m,$s,$p,$e['evidence_hash']));self::assertSame('DF_NATIVE_IMAGE_UPLOAD_WIRE_1',$wire['schema']);self::assertSame('shop2-'.substr($f['package_hash'],0,16).'.png',$wire['file_name']);self::assertSame($e['payload_fingerprint'],$wire['payload_fingerprint']);self::assertArrayNotHasKey('contents',$wire);self::assertArrayNotHasKey('body',$wire);self::assertSame('/v1/uploads/images.json',$wire['endpoint']);
   self::assertWPError(Association::propose($scope,1,$m,$s,$p,str_repeat('f',64),2,time(),time()+600,wp_generate_uuid4()));
   $next=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],2,time(),time()+600,wp_generate_uuid4()));self::assertSame(2,$next['version']);self::assertWPError(Association::read($scope,1,$m,$s,$p));
   PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,3,$next['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();$a=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));self::assertSame(2,$a['association_version']);
   global $wpdb;self::assertSame(1,$wpdb->update(Tables::product_versions(),['notes'=>'changed association source'],['id'=>$f['business_version_id']],['%s'],['%d']));self::assertWPError(Association::read($scope,1,$m,$s,$p));self::assertWPError(Association::uploadDescriptor($scope,1,$m,$s,$p,$e['evidence_hash']));
   PortalAccessFixture::login($this->fixture->reviewer);$r=PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,4,$next['proposal_hash'],'REVOKED',wp_generate_uuid4()));self::assertSame('REVOKED',$r['status']);$this->fixture->owner();self::assertWPError(Association::read($scope,1,$m,$s,$p));self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  } finally {remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
 public function testMissingSessionCannotProbeBusinessSourceLocator():void {
  $v=PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));$scope=new DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new DigiForge\Connector\Printify\CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);$probed=false;
  $filter=static function(string $sql)use(&$probed):string{if(str_contains($sql,'FROM '.Tables::pod_mappings()))$probed=true;return $sql;};add_filter('query',$filter,PHP_INT_MIN);
  try{self::assertWPError(Association::read($scope,0,1234,1234,str_repeat('a',64)));self::assertFalse($probed,'No business mapping query may precede current scoped/session authorization.');}finally{remove_filter('query',$filter,PHP_INT_MIN);}
 }

}
