<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
final class ScopedProviderConnectionTest extends WP_UnitTestCase
{
 public function testLegacyConnectionHasNoReviewedAuthority():void {
  DigiForge\Core\Activator::activate();$actors=ReviewedLocalFixture::actors('personalized_pod');
  $input=ReviewedOwnershipFixture::create($this);
  self::assertTrue(class_exists(DigiForge\POD\ScopedProviderConnection::class),'DF-OWN-001 scoped relationship is missing');
  $result=(new DigiForge\POD\ScopedProviderConnection())->verified('personalized_pod',$input['integration_id'],'printify.catalog.read');
  self::assertWPError($result);
 }

 private function fixture(?int $expires=null):array {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());$actors=ReviewedLocalFixture::actors('personalized_pod');
  $old=ReviewedOwnershipFixture::create($this);$id=$old['integration_id'];
  self::assertTrue((new DigiForge\Integrations\Repository())->storeSecret($id,'personal_access_token','synthetic-only-connection-material'));
  self::assertNotWPError((new DigiForge\Integrations\Repository())->setEnabled($id,true));
  $review=ScopedProviderConnectionFixture::approve($id,$actors['author'],$expires);
  return [$id,$review,$actors,new DigiForge\POD\ScopedProviderConnection()];
 }
 public function testValidReviewedBindingRemainsLocalAndLocksStayOn():void {
  [$id,$review,$actors,$service]=$this->fixture();$valid=$service->verified('personalized_pod',$id,'printify.catalog.read');self::assertNotWPError($valid);
  self::assertSame('VERIFIED_LOCAL_BINDING',$valid['status']);self::assertFalse($valid['provider_identity_verified']);self::assertFalse($valid['external_execution_authorized']);
  self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::is_enabled('printify'));
  self::assertStringNotContainsString('synthetic-only-connection-material',wp_json_encode($valid));
 }
 public function testForeignAndDeferredShopsNeverInheritSharedMaterialAuthority():void {
  [$id,$review,$actors,$service]=$this->fixture();
  foreach(['digital','standard_pod','jewelry']as $shop){ShopAccessFixture::authorizeActor($actors['author'],[$shop],['manage_digiforge','manage_digiforge_connections']);self::assertWPError($service->verified($shop,$id,'printify.catalog.read'));}
  self::assertSame('DEFERRED_SHOP',$service->status('jewelry',$id)['status']);self::assertSame('NOT_APPLICABLE',$service->status('digital',$id)['status']);
 }
 public function testDeletedRelationAndDeletedRevocationCannotRestoreAuthority():void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$table=DigiForge\Database\ProviderConnectionSchema::table();$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log());
  wp_set_current_user($review['reviewer']);$a=$review['approved'];self::assertNotWPError($service->decide('personalized_pod',$id,'printify.catalog.read',$a['revision'],$a['proposal_hash'],'REVOKED','revoke-'.wp_generate_uuid4()));
  self::assertSame('REVOKED',$service->status('personalized_pod',$id)['status']);
  $wpdb->delete($table,['integration_id'=>$id]);self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_local_review_%'");
  self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));self::assertGreaterThan($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log()));
 }
 /** @dataProvider driftCases */
 public function testDependencyDriftDenies(string $change):void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$repo=new DigiForge\Integrations\Repository();$i=$repo->find($id);$c=$i['config'];
  if($change==='material')$repo->storeSecret($id,'personal_access_token','different-synthetic-material');
  elseif($change==='business')$wpdb->update(DigiForge\Database\Tables::businesses(),['status'=>'INACTIVE'],['id'=>$service->verified('personalized_pod',$id,'printify.catalog.read')['business_id']]);
  elseif($change==='environment')self::assertSame(1,$wpdb->update(DigiForge\Database\Tables::integrations(),['environment'=>'sandbox'],['id'=>$id]));
  elseif($change==='site')update_option('siteurl','http://different-installation.invalid');
  else{$c[$change]=match($change){'account_reference'=>'different-account','shop_id'=>'999999','shop_key'=>'standard_pod',default=>[]};$repo->update($id,['config'=>$c]);}
  self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public static function driftCases():array{return array_map(static fn($v)=>[$v],['material','business','environment','site','account_reference','shop_id','shop_key','catalog_read_scope']);}
 public function testStaleReplayAndSelfApprovalDeny():void {
  [$id,$review,$actors,$service]=$this->fixture();$a=$review['approved'];
  self::assertWPError($service->decide('personalized_pod',$id,'printify.catalog.read',1,$a['proposal_hash'],'APPROVED','stale-'.wp_generate_uuid4()));
  $p=$service->propose('personalized_pod',$review['input'],time()-1,time()+600,'new-proposal-request-001',$a['revision']);self::assertNotWPError($p);
  self::assertWPError($service->decide('personalized_pod',$id,'printify.catalog.read',$p['revision'],$p['proposal_hash'],'APPROVED','self-approve-request-001'));
  self::assertWPError($service->propose('personalized_pod',$review['input'],time()-1,time()+600,'new-proposal-request-001',$p['revision']));
  self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testExpiredBindingDenies():void {
  [$id,$review,$actors,$service]=$this->fixture(time()+2);usleep(2100000);self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testDuplicateExternalIdentityIsAmbiguous():void {
  [$id,$review,$actors,$service]=$this->fixture();$repo=new DigiForge\Integrations\Repository();$i=$repo->find($id);
  self::assertNotWPError($repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'duplicate-'.wp_generate_uuid4(),'status'=>'CONFIGURED','config'=>$i['config']]));
  self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testMissingRelationalRowDeniesEvenWithApprovedReview():void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$wpdb->delete(DigiForge\Database\ProviderConnectionSchema::table(),['binding_hash'=>$review['approved']['proposal_hash']]);self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testUnzonedAndInvalidObservationCannotPropose():void {
  [$id,$review,$actors,$service]=$this->fixture();foreach(['2026-10-18 00:00:00','2026-99-99T00:00:00Z']as $date){$input=$review['input'];$input['observed_at']=$date;self::assertWPError($service->propose('personalized_pod',$input,time()-1,time()+600,'invalid-date-request-001',2));}
 }

 public function testPortalCollectionIsScopedRedactedAndDigitalIndependent():void {
  [$id,$review,$actors,$service]=$this->fixture();
  ShopAccessFixture::authorizeActor($actors['author'],['personalized_pod'],['manage_digiforge','manage_digiforge_connections','manage_digiforge_settings','manage_digiforge_pod','manage_digiforge_orders']);
  $get=$_GET;try{$_GET=['df_view'=>'fulfillment','df_shop'=>'personalized_pod'];$html=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('Scoped provider connection evidence',$html);self::assertStringContainsString('VERIFIED_LOCAL_BINDING',$html);self::assertStringNotContainsString('synthetic-only-connection-material',$html);}finally{$_GET=$get;}
  $out=$service->forShop('personalized_pod');self::assertSame('AVAILABLE',$out['query_state']);self::assertContains($id,array_column($out['items'],'integration_id'));self::assertStringNotContainsString('synthetic-only-connection-material',wp_json_encode($out));
  ShopAccessFixture::authorizeActor($actors['author'],['digital'],['manage_digiforge','manage_digiforge_connections']);self::assertSame('NOT_APPLICABLE',$service->forShop('digital')['query_state']);self::assertSame([],$service->forShop('digital')['items']);
  wp_set_current_user(0);self::assertSame('ACCESS_DENIED',$service->forShop('personalized_pod')['query_state']);
 }
 public function testRelationalTamperingAndDatabaseOutageDeny():void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$table=DigiForge\Database\ProviderConnectionSchema::table();
  $fault=static fn(string $q):string=>str_contains($q,'SELECT * FROM '.$table)?'SELECT FROM /* synthetic read failure */':$q;
  $previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
  try{self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
  self::assertSame(1,$wpdb->update($table,['business_id'=>999999],['binding_hash'=>$review['approved']['proposal_hash']]));self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testRelationAndReviewRollBackTogetherOnInsertFailure():void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$table=DigiForge\Database\ProviderConnectionSchema::table();
  $fault=static fn(string $q):string=>str_starts_with($q,'INSERT INTO `'.$table.'`')?'SELECT FROM /* synthetic insert failure */':$q;
  $before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log()." WHERE object_type='reviewed_local_evidence'");$previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
  try{self::assertWPError($service->propose('personalized_pod',$review['input'],time()-1,time()+600,'failed-insert-request-001',2));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
  self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log()." WHERE object_type='reviewed_local_evidence'"));self::assertNotWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testMissingRelationCannotBeBypassedByCatalogGrantApproval():void {
  [$id,$review,$actors,$service]=$this->fixture();global $wpdb;$i=(new DigiForge\Integrations\Repository())->find($id);$scope=$i['config']['catalog_read_scope'];$wpdb->delete(DigiForge\Database\ProviderConnectionSchema::table(),['integration_id'=>$id]);
  $result=((new DigiForge\POD\PrintifyCatalogReadRepository())->approve(['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>get_current_user_id()]+$scope+['expires_at'=>gmdate('c',time()+600),'max_requests'=>1,'requests'=>[['kind'=>'blueprint','blueprint_id'=>101]]],'missing-relation-grant'));self::assertWPError($result);self::assertSame('provider_connection_missing_or_ambiguous_evidence',$result->get_error_code());
 }
 public function testMigrationIsAdditiveIdempotentAndRecoversWithoutApprovalBackfill():void {
  global $wpdb;$schema=DigiForge\Database\ProviderConnectionSchema::class;$table=$schema::table();
  self::assertTrue($schema::migrateIfNeeded());$ddl=[];$observer=static function(string $q)use(&$ddl):string{if(preg_match('/^(CREATE|ALTER) TABLE /i',$q))$ddl[]=$q;return $q;};add_filter('query',$observer);try{self::assertTrue($schema::migrateIfNeeded());}finally{remove_filter('query',$observer);}self::assertSame([],$ddl);
  remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
  $previous=$wpdb->suppress_errors(true);$fault=static fn(string $q):string=>str_starts_with($q,'CREATE TABLE '.$table)?'SELECT FROM /* synthetic schema failure */':$q;
  try{
   self::assertNotFalse($wpdb->query('DROP TABLE IF EXISTS '.$table));add_filter('query',$fault);
   try{self::assertFalse($schema::migrateIfNeeded());}finally{remove_filter('query',$fault);}
   self::assertTrue($schema::migrateIfNeeded());self::assertSame('0',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$table));self::assertSame('1',get_option('digiforge_provider_connection_schema'));
  }finally{$schema::migrateIfNeeded();$wpdb->suppress_errors($previous);add_filter('query',[$this,'_create_temporary_tables']);add_filter('query',[$this,'_drop_temporary_tables']);}
 }

 public function testSharedCredentialDoesNotAuthorizeASecondIntegration():void {
  [$id,$review,$actors,$service]=$this->fixture();$repo=new DigiForge\Integrations\Repository();$old=$repo->find($id);$c=$old['config'];$c['shop_id']='999000111';
  $other=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'shared-material-'.wp_generate_uuid4(),'status'=>'CONFIGURED','config'=>$c]);self::assertNotWPError($other);
  self::assertTrue($repo->storeSecret($other['id'],'personal_access_token','synthetic-only-connection-material'));self::assertNotWPError($repo->setEnabled($other['id'],true));
  self::assertWPError($service->verified('personalized_pod',$other['id'],'printify.catalog.read'));self::assertNotWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
 public function testRestoringPriorConfigurationDoesNotReactivateOldReview():void {
  [$id,$review,$actors,$service]=$this->fixture();$repo=new DigiForge\Integrations\Repository();$old=$repo->find($id);$c=$old['config'];$c['account_reference']='synthetic-reassigned';self::assertNotWPError($repo->update($id,['config'=>$c]));
  self::assertNotWPError($repo->update($id,['config'=>$old['config']]));self::assertWPError($service->verified('personalized_pod',$id,'printify.catalog.read'));
 }
}
