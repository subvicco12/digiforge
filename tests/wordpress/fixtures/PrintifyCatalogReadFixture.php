<?php
declare(strict_types=1);
use DigiForge\Database\Tables;
/** Real signed grants, isolated local database and synthetic transport only. */
require_once __DIR__.'/DurableCatalogFixture.php';
trait PrintifyCatalogReadFixture {
 use DurableCatalogFixture;
 private array $input;private int $owner;
 public function setUp():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->snapshotCatalogSession();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($this->owner,['digital','personalized_pod','standard_pod','jewelry'],['manage_digiforge_connections','manage_digiforge_pod']);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_generate_uuid4();$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['shop_key'=>'personalized_pod','catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));ScopedProviderConnectionFixture::approve($id,$this->owner);
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
  $this->durableCatalogSession();
 }
 private function unlocked():void {global $wpdb;foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false]as $key=>$v)$wpdb->query($wpdb->prepare('UPDATE '.Tables::settings().' SET setting_value=%s WHERE setting_key=%s',wp_json_encode($v),$key));}
 private function client(int &$calls):DigiForge\POD\PrintifyCatalogClient {return new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/catalog/blueprints/101/print_providers/2/variants.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"synthetic only","placeholders":[]},{"id":203}]}'];});}
}
