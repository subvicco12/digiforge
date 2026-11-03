<?php
declare(strict_types=1);
use DigiForge\POD\ReviewedOwnershipBinding;
/** Local synthetic evidence only: these tests never call a provider. */
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
final class ReviewedOwnershipBindingTest extends WP_UnitTestCase
{
 private function approved():array {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$actors=ReviewedLocalFixture::actors('personalized_pod');wp_set_current_user($actors['author']);$input=ReviewedOwnershipFixture::create($this);$repo=new ReviewedOwnershipBinding();
  $proposal=$repo->propose('personalized_pod',$input,time()-1,time()+600,'ownership-proposal-'.wp_generate_uuid4(),0);$this->assertNotWPError($proposal);
  $key=hash('sha256',json_encode(['personalized_pod','test',$input['provider_mapping_id'],$input['production_template_id']],JSON_UNESCAPED_SLASHES));
  wp_set_current_user($actors['reviewer']);$approved=$repo->decide('personalized_pod',$key,$proposal['revision'],$proposal['proposal_hash'],'APPROVED','ownership-approve-'.wp_generate_uuid4());$this->assertNotWPError($approved);
  return [$repo,$key,$input,$approved,$actors];
 }
 public function test_independent_local_review_binds_exact_synthetic_stored_tuple():void {
  [$repo,$key,$input,$approved]=$this->approved();$verified=$repo->verified('personalized_pod',$key);$this->assertNotWPError($verified);$this->assertSame('APPROVED',$verified['status']);$this->assertSame('SYNTHETIC',$verified['payload']['evidence_state']);$this->assertFalse($verified['payload']['external_execution_authorized']);$this->assertFalse($verified['payload']['provider_identity_verified']);$this->assertSame($input['provider_mapping_id'],$verified['payload']['provider_mapping_id']);$this->assertSame(64,strlen($verified['payload']['integration_version']));
  $request=new WP_REST_Request('GET','/digiforge/v1/reviewed-contracts/personalized_pod/ownership');$request->set_url_params(['shop'=>'personalized_pod','kind'=>'ownership']);$request->set_query_params(['key'=>$key]);$response=(new DigiForge\REST\ReviewedContractsController())->read($request);$this->assertInstanceOf(WP_REST_Response::class,$response);$this->assertSame('REVIEW_REQUIRED',$response->get_data()['native_placement_plan']['classification']);$this->assertFalse($response->get_data()['native_placement_plan']['external_execution_authorized']);
 }
 public function test_integration_identity_change_invalidates_review_without_releasing_safety():void {
  [$repo,$key,$input]=$this->approved();global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT config FROM '.DigiForge\Database\Tables::integrations().' WHERE id=%d',$input['integration_id']));$config=json_decode($raw,true);$config['account_reference']='synthetic-different-account';$this->assertSame(1,$wpdb->update(DigiForge\Database\Tables::integrations(),['config'=>wp_json_encode($config)],['id'=>$input['integration_id']]));$this->assertWPError($repo->verified('personalized_pod',$key));$this->assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function test_revoked_local_review_never_verifies():void {
  [$repo,$key,$input,$approved]=$this->approved();$revoked=$repo->decide('personalized_pod',$key,$approved['revision'],$approved['proposal_hash'],'REVOKED','ownership-revoke-'.wp_generate_uuid4());$this->assertNotWPError($revoked);$this->assertWPError($repo->verified('personalized_pod',$key));
 }
 public function test_catalog_drift_invalidates_current_and_verified():void {
  [$repo,$key]=$this->approved();global $wpdb;$this->assertSame(1,$wpdb->update(DigiForge\Database\Tables::pod_catalog(),['source_revision'=>'synthetic-drift'],['provider'=>'printify','environment'=>'test','provider_product_key'=>'101','provider_variant_key'=>'2:202']));$this->assertWPError($repo->current('personalized_pod',$key));$this->assertWPError($repo->verified('personalized_pod',$key));
 }
 public function test_missing_authentic_configuration_remains_blocked_even_with_stored_product_ancestry():void {
  DigiForge\Core\Activator::activate();$actors=ReviewedLocalFixture::actors('personalized_pod');wp_set_current_user($actors['author']);$input=ReviewedOwnershipFixture::create($this);global $wpdb;$raw=$wpdb->get_var($wpdb->prepare('SELECT config FROM '.DigiForge\Database\Tables::integrations().' WHERE id=%d',$input['integration_id']));$config=json_decode($raw,true);unset($config['account_reference']);$wpdb->update(DigiForge\Database\Tables::integrations(),['config'=>wp_json_encode($config)],['id'=>$input['integration_id']]);$this->assertWPError((new ReviewedOwnershipBinding())->propose('personalized_pod',$input,time()-1,time()+600,'ownership-missing-account',0));
 }
 public function test_duplicate_configured_provider_shop_and_foreign_shop_are_denied():void {
  [$repo,$key,$input]=$this->approved();self::assertWPError($repo->verified('digital',$key));
  global $wpdb;$original=(new DigiForge\Integrations\Repository())->find($input['integration_id']);self::assertNotWPError($original);
  $duplicate=(new DigiForge\Integrations\Repository())->create(['provider'=>'printify','environment'=>'test','connection_key'=>'conflicting-'.wp_generate_uuid4(),'status'=>'CONFIGURED','config'=>$original['config']]);self::assertNotWPError($duplicate);
  self::assertWPError($repo->verified('personalized_pod',$key));
 }
 public function test_unknown_identity_fields_are_rejected_before_storage():void {
  $result=(new ReviewedOwnershipBinding())->propose('personalized_pod',['integration_id'=>1,'provider_mapping_id'=>1,'production_template_id'=>1,'source_sha256'=>str_repeat('a',64),'evidence_reference'=>'local://synthetic','account_id'=>999],time(),time()+600,'ownership-test-unknown',0);
  $this->assertWPError($result);$this->assertSame('digiforge_ownership_invalid_input',$result->get_error_code());
 }
 public function test_numeric_strings_cannot_substitute_for_typed_record_ids():void {
  $result=(new ReviewedOwnershipBinding())->propose('personalized_pod',['integration_id'=>'1','provider_mapping_id'=>1,'production_template_id'=>1,'source_sha256'=>str_repeat('a',64),'evidence_reference'=>'local://synthetic'],time(),time()+600,'ownership-test-types',0);
  $this->assertWPError($result);$this->assertSame('digiforge_ownership_invalid_input',$result->get_error_code());
 }
 public function test_missing_stored_ancestry_cannot_create_ownership():void {
  $result=(new ReviewedOwnershipBinding())->propose('personalized_pod',['integration_id'=>PHP_INT_MAX,'provider_mapping_id'=>PHP_INT_MAX,'production_template_id'=>PHP_INT_MAX,'source_sha256'=>str_repeat('a',64),'evidence_reference'=>'local://synthetic'],time(),time()+600,'ownership-test-absent',0);
  $this->assertWPError($result);
 }
 public function test_missing_binding_never_verifies():void {
  $this->assertWPError((new ReviewedOwnershipBinding())->verified('personalized_pod',str_repeat('a',64)));
 }
}
