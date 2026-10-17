<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
use DigiForge\Listings\ListingPreparationQuotaRepository;
final class ListingPreparationQuotaAcceptanceTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 private function product(string $shop):array {
  $research=new DigiForge\Research\Repository();$candidate=$research->createCandidate(['shop_key'=>$shop,'title'=>'Local quota '.wp_generate_uuid4()]);self::assertNotWPError($candidate);self::assertNotWPError($research->review((int)$candidate['id'],'APPROVED'));$opportunity=$research->promote((int)$candidate['id']);self::assertNotWPError($opportunity);$factory=new DigiForge\ProductFactory\Repository();$family=$factory->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>'Quota fixture']);self::assertNotWPError($family);$product=$factory->create('product',['product_family_id'=>$family['id'],'name'=>'Quota product']);self::assertNotWPError($product);return ['id'=>13,'product_id'=>$product['id']];
 }
 private function policy(string $shop,string $environment):void {self::assertNotWPError((new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$shop,'currency'=>'USD','budgets'=>['month'=>50],'stages'=>['listing_prepare'=>['limit'=>1,'estimated_unit_cost'=>999]]],$environment));}
 public function testOneLocalSlotReplaysWithoutAiChargeAndConflictingRunFails():void {
  $v=$this->product('digital');$this->policy('digital','sandbox');$repo=new ListingPreparationQuotaRepository();$bundle=['id'=>5,'manifest'=>'approved fixture','checksum_sha256'=>hash('sha256','fixture')];$run=['run_id'=>'local-listing-cycle','started_at'=>gmdate('Y-m-d H:i:s')];
  $first=$repo->reserve('digital','sandbox',$v,$bundle,'first',$run);self::assertNotWPError($first);self::assertSame(1,(int)$first['quantity']);self::assertSame(0.0,(float)$first['estimated_cost']);self::assertSame(0.0,(float)$first['actual_cost']);$replay=$repo->reserve('digital','sandbox',$v,$bundle,'first',$run);self::assertNotWPError($replay);self::assertSame($first['id'],$replay['id']);self::assertTrue($replay['idempotent_replay']);
  $changed=$run;$changed['run_id']='another-run';self::assertSame('listing_preparation_quota_replay_conflict',$repo->reserve('digital','sandbox',$v,$bundle,'first',$changed)->get_error_code());self::assertSame('listing_preparation_quota_limit_reached',$repo->reserve('digital','sandbox',$v,$bundle,'second',$run)->get_error_code());global $wpdb;self::assertSame('1',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::shop_ai_usage().' WHERE workflow=%s','listing_factory_sandbox')));
 }
 public function testPolicyScopeAndLocksDoNotConsumeAnotherEnvironmentOrShop():void {
  $a=$this->product('digital');$b=$this->product('personalized_pod');$this->policy('digital','sandbox');$this->policy('digital','production');$this->policy('personalized_pod','sandbox');$repo=new ListingPreparationQuotaRepository();$bundle=['id'=>5];self::assertNotWPError($repo->reserve('digital','sandbox',$a,$bundle,'one'));self::assertNotWPError($repo->reserve('digital','production',$a,$bundle,'two'));self::assertNotWPError($repo->reserve('personalized_pod','sandbox',$b,$bundle,'three'));
  self::assertWPError($repo->reserve('personalized_pod','sandbox',$a,$bundle,'wrong-shop'));
  $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock=DigiForge\AI\ShopAiGovernanceRepository::generationLockName('digital');self::assertSame('1',$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));try{self::assertSame('listing_preparation_quota_busy',$repo->reserve('digital','sandbox',$a,$bundle,'blocked')->get_error_code());}finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();}
 }
 public function testManualMissingPlanCannotBeMistakenForExplicitReviewedRun():void {
  $v=$this->product('jewelry');$repo=new ListingPreparationQuotaRepository();self::assertSame('MANUAL_UNPLANNED',$repo->reserve('jewelry','sandbox',$v,['id'=>1],'manual')['quota_state']);self::assertSame('listing_preparation_quota_policy_required',$repo->reserve('jewelry','sandbox',$v,['id'=>1],'explicit',['run_id'=>'a','started_at'=>gmdate('Y-m-d H:i:s')])->get_error_code());
 }
}
