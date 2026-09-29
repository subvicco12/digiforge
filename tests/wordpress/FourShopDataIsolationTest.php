<?php
declare(strict_types=1);
final class FourShopDataIsolationTest extends WP_UnitTestCase {
 protected function setUp():void { parent::setUp(); DigiForge\Core\Activator::activate(); }
 public function testOperationalProjectionDoesNotLeakShopPolicyOrUsageAcrossFourShops():void {
  global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);$shops=['digital','personalized_pod','standard_pod','jewelry'];
  foreach($shops as $i=>$shop){
   $policy=wp_json_encode(['marker'=>'policy-'.$shop]);
   $wpdb->insert($t::shop_ai_policies(),['shop_key'=>$shop,'environment'=>'production','currency'=>'USD','policy'=>$policy,'policy_hash'=>hash('sha256',(string)$policy),'state'=>'ACTIVE','created_by'=>0,'created_at'=>$now,'updated_at'=>$now]);
   $wpdb->insert($t::shop_ai_usage(),['shop_key'=>$shop,'workflow'=>'wf-'.$shop,'run_id'=>'run-'.$shop,'run_started_at'=>$now,'stage'=>'research','model_key'=>'model','quantity'=>$i+1,'estimated_cost'=>'0.100000','actual_cost'=>'0.090000','currency'=>'USD','occurred_at'=>$now]);
  }
  $rm=new DigiForge\Portal\ShopOperationsReadModel();
  foreach($shops as $shop){$s=$rm->snapshot($shop);self::assertSame($shop,$s['shop']);self::assertSame('AVAILABLE',$s['query_state']['ai_policies']);self::assertSame('AVAILABLE',$s['query_state']['ai_usage']);self::assertCount(1,$s['ai_policies']);self::assertCount(1,$s['ai_usage']);self::assertSame($shop,$s['ai_policies'][0]['shop_key']);self::assertSame($shop,$s['ai_usage'][0]['shop_key']);self::assertFalse($s['external_execution_performed']);}
 }
 public function testUnknownShopNormalizesToAllRatherThanInventingScope():void { self::assertSame(DigiForge\Portal\ShopOperationsReadModel::ALL,DigiForge\Portal\ShopOperationsReadModel::normalize('not-a-shop')); }
}
