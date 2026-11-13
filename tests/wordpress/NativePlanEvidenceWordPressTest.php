<?php
declare(strict_types=1);
use DigiForge\Connector\Printify\{NativePlanEvidence,ShopBinding,CredentialReference};
use DigiForge\POD\{ScopedProviderConnection,PrintifyProductPipeline,ProductPackageRepository};
use DigiForge\Security\ReviewedLocalEvidence;
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
require_once __DIR__.'/fixtures/NativeApprovedPackageFixture.php';
/** Real protected synthetic approvals; no fabricated native grant and no sender invocation. */
final class NativePlanEvidenceWordPressTest extends WP_UnitTestCase {
 use NativeApprovedPackageFixture;
 private PrintifyApiApprovalFixture $fixture;private $http;private $query;
 public static function set_up_before_class():void{parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());}
 protected function setUp():void {
  parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();DigiForge\Core\Settings::protectProduction();
  $this->http=static function(){throw new RuntimeException('Provider HTTP prohibited');};$this->query=static function(string $q):string{if(preg_match('/^\s*SELECT\s+[^;]*\bciphertext\b/i',$q))throw new RuntimeException('Credential read prohibited');return $q;};add_filter('pre_http_request',$this->http,PHP_INT_MAX);add_filter('query',$this->query,PHP_INT_MAX);
 }
 protected function tearDown():void {try{remove_filter('pre_http_request',$this->http,PHP_INT_MAX);remove_filter('query',$this->query,PHP_INT_MAX);self::assertTrue(DigiForge\Core\Settings::safety_locked());$this->fixture->restore();}finally{parent::tearDown();}}
 private function scope():ShopBinding {$v=PrintifyApiApprovalFixture::checked((new ScopedProviderConnection())->verified('personalized_pod',$this->fixture->integration,'printify.connection.read'));return new ShopBinding(2,'personalized_pod',$this->fixture->integration,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($this->fixture->integration),$v['binding_hash'],$v['expires_at']);}
 public function testMissingServerPlanAndSelfHashedReceiptNeverAuthorize():void {$scope=$this->scope();self::assertWPError(NativePlanEvidence::productPlan($scope,1,str_repeat('f',64)));self::assertWPError(NativePlanEvidence::receipt($scope,1,str_repeat('a',64)));}
 public function testExistingProtectedPlanAndCurrentPolicyJoinStayNonexecutingAndRevocationDenies():void {
  $connection=new ScopedProviderConnection();$proposal=PrintifyApiApprovalFixture::checked($connection->propose('personalized_pod',['integration_id'=>$this->fixture->integration,'capability'=>'printify.product.prepare','source_sha256'=>hash('sha256','native-local-plan-proof'),'evidence_reference'=>'local://isolated/native-plan-proof','observed_at'=>gmdate('c',time()-1)],time(),time()+1200,wp_generate_uuid4(),0));
  PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked($connection->decide('personalized_pod',$this->fixture->integration,'printify.product.prepare',$proposal['revision'],$proposal['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
  $package=$this->approvedNativePackage();$v=PrintifyApiApprovalFixture::checked((new ProductPackageRepository())->read('personalized_pod',$package['package']));$design=$v['record']['commercial_design_metadata']['design_hash'];$asset=PrintifyApiApprovalFixture::checked((new DigiForge\Research\DesignFactoryRepository())->read('personalized_pod',$design));$ref=$asset['record']['asset']['raster_references'][0]['reference'];
  $pipeline=new PrintifyProductPipeline();$plan=PrintifyApiApprovalFixture::checked($pipeline->prepare($package['workflow'],$package['package'],$this->fixture->integration,'IMAGE_UPLOAD',['asset_reference'=>$ref]));$scope=$this->scope();self::assertWPError(NativePlanEvidence::productPlan($scope,1,$plan['hash']));
  $payload=PrintifyApiApprovalFixture::checked($pipeline->approvalPayload($plan['hash']));$key='printify-product:'.$plan['hash'];$p=PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::propose('policy','personalized_pod',$key,$payload,time(),time()+900,wp_generate_uuid4(),0));
  PortalAccessFixture::login($this->fixture->reviewer);$a=PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->fixture->owner();
  $r=NativePlanEvidence::productPlan($scope,1,$plan['hash']);self::assertNotWPError($r);self::assertSame($plan['hash'],$r['plan_hash']);self::assertSame($a['proposal_hash'],$r['approval_hash']);self::assertFalse($r['operational_grant_issued']);self::assertFalse($r['external_execution_authorized']);self::assertFalse($r['independent_checkpoint_verified']);self::assertStringNotContainsString('base64',wp_json_encode($r));self::assertWPError($pipeline->authorize($plan['hash']));
  PortalAccessFixture::login($this->fixture->reviewer);PrintifyApiApprovalFixture::checked(ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$a['revision'],$a['proposal_hash'],'REVOKED',wp_generate_uuid4()));$this->fixture->owner();self::assertWPError(NativePlanEvidence::productPlan($scope,1,$plan['hash']));
 }
}
