<?php
declare(strict_types=1);
/** Actual core association/descriptor compatibility only: NOT native mutation admission or geometry certification. */
use DigiForge\Connector\Printify\{NativeBusinessMutationAssociation as Association,NativePortContract,NativeHeldJournalSchema};
use DigiForgePrintifyConnector\Preparation\{CoreUploadWireContract,PreparationService};
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
require_once DIGIFORGE_PATH.'tests/wordpress/fixtures/NativeBusinessMutationFixture.php';
final class NativeCoreUploadWireAcceptance extends WP_UnitTestCase
{
 use NativeBusinessMutationFixture;
 private PrintifyApiApprovalFixture $fixture;private array $tables=[];
 protected function setUp():void{parent::setUp();remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();global $wpdb;$t=DigiForge\Database\Tables::class;foreach([$t::opportunities(),$t::product_families(),$t::products(),$t::product_versions(),$t::production_plans(),$t::pod_mappings(),$t::pod_business_mappings(),$t::asset_specs(),$t::production_plan_assets()]as $table)$this->tables[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);}
 protected function tearDown():void{try{global $wpdb;$wpdb->query('ROLLBACK');foreach(NativeHeldJournalSchema::tables()as $t)$wpdb->query('DROP TABLE IF EXISTS '.$t);foreach($this->tables as $t=>$rows){$wpdb->query('DELETE FROM '.$t);foreach($rows as $row)$wpdb->insert($t,$row,array_fill(0,count($row),'%s'));}$this->fixture->restore();}finally{parent::tearDown();}}
 public function testActualApprovedDescriptorMatchesLocalBytesButDoesNotReplaceMissingArtworkEvidence():void
 {
  self::assertTrue(class_exists(Association::class),'Actual core source association producer required, not a mock.');self::assertTrue(class_exists(CoreUploadWireContract::class));
  $f=$this->businessMutationFixture($this->fixture);$scope=$f['scope'];$m=$f['mapping_id'];$s=$f['spec_id'];$p=$f['plan_hash'];$http=0;
  $deny=static function()use(&$http){++$http;throw new RuntimeException('Provider HTTP is forbidden');};add_filter('pre_http_request',$deny,PHP_INT_MAX);
  try{
   $source=PrintifyApiApprovalFixture::checked(Association::source($scope,1,$m,$s,$p));
   self::assertWPError(Association::read($scope,1,$m,$s,$p));
   $proposal=PrintifyApiApprovalFixture::checked(Association::propose($scope,1,$m,$s,$p,$source['source_hash'],0,time(),time()+1200,wp_generate_uuid4()));
   self::assertWPError(Association::decide($scope,1,$m,$s,$p,$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));
   PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(Association::decide($scope,$this->fixture->reviewer,$m,$s,$p,$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
   $a=PrintifyApiApprovalFixture::checked(Association::read($scope,1,$m,$s,$p));self::assertTrue($a['business_package_binding_approved']);self::assertFalse($a['mutation_authorized']);self::assertFalse($a['dispatch_permitted']);self::assertNull($a['native_product_version']);self::assertFalse($a['source_approval_cas_verified']);
   $d=PrintifyApiApprovalFixture::checked(Association::uploadDescriptor($scope,1,$m,$s,$p,$a['current_source_evidence_hash']));
   $plan=PrintifyApiApprovalFixture::checked((new DigiForge\POD\PrintifyProductPipeline())->plan($p));$body=$plan['request']['body'];$bytes=base64_decode($body['contents'],true);self::assertIsString($bytes);
   $validated=CoreUploadWireContract::validate($d,$scope->fingerprint(),1,hash('sha256',$bytes),$body['contents']);
   self::assertSame($d['payload_fingerprint'],NativePortContract::hash(['method'=>'POST','endpoint'=>'/v1/uploads/images.json','body'=>$body]));self::assertSame($d['descriptor_hash'],$validated['descriptor_hash']);self::assertSame('shop2-'.substr($f['package_hash'],0,16).'.png',$d['file_name']);self::assertArrayNotHasKey('contents',$d);self::assertArrayNotHasKey('body',$d);
   // Core layout reservations are not supplier safe zones. A reviewed wire descriptor must not fabricate these inputs.
   $context=['actor_id'=>1,'logical_reference'=>'actual-core-wire','approval_reference'=>$a['review_hash'],'authorization_revision'=>$scope->revision,'product_version'=>0,'expected_product_version'=>0];
   try{(new PreparationService())->uploadFromCoreDescriptor($scope,$bytes,[],$d,$context);self::fail('Missing rights/geometry evidence was bypassed');}catch(InvalidArgumentException){self::assertTrue(true);}
   self::assertWPError(Association::uploadDescriptor($scope,1,$m,$s,$p,str_repeat('f',64)));
   global $wpdb;self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::product_versions(),['notes'=>'invalidated actual source'],['id'=>$f['business_version_id']],['%s'],['%d']));self::assertWPError(Association::read($scope,1,$m,$s,$p));self::assertWPError(Association::uploadDescriptor($scope,1,$m,$s,$p,$d['source_evidence_hash']));
   // Self-consistent old descriptor bytes still parse; current core rejection is the authority, not this hash.
   self::assertSame($d['descriptor_hash'],CoreUploadWireContract::validate($d,$scope->fingerprint(),1,hash('sha256',$bytes),$body['contents'])['descriptor_hash']);
   self::assertSame(0,$http);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());
  }finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);}
 }
}
