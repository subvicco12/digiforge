# Reproducible offline review probes

Applies ONLY to exact #1189 `8ea1f07dbe91b65272fe9f20a5e1388838c2afdd`. Review artifacts, not implementation changes or passing acceptance tests. The three expected-denial assertions currently FAIL. Test code below reuses public synthetic fixture setup from `tests/wordpress/PrintifyCatalogScopedSmokeTest.php`; the literal fixture token is synthetic, not an integration credential. No real owner approval or provider identity is represented.

Run only in an isolated disposable WordPress test database with the existing test plugin bootstrap and dependencies. Never point this at a website, production or staging database. Add the global deny-HTTP hook before loading the test plugin; pass the candidate's normal WordPress test bootstrap through a scratch bootstrap:

```php
<?php
// Scratch bootstrap only, outside the repository. Paths must point to disposable fixtures.
define('WP_HTTP_BLOCK_EXTERNAL', true);
require_once getenv('WP_TESTS_DIR').'/includes/functions.php';
tests_add_filter('muplugins_loaded', static function (): void {
    add_filter('pre_http_request', static fn()=>new WP_Error('review_http_denied','Actual HTTP forbidden.'), PHP_INT_MAX);
}, -PHP_INT_MAX);
require getenv('DIGIFORGE_REVIEW_REPO').'/tests/wordpress/bootstrap.php';
```

Copy the next PHP block to a scratch file named `PrintifyIntegrationReviewProbe.php`. Run with the candidate's PHPUnit and scratch bootstrap. The transport always returns synthetic arrays, and the global hook denies accidental actual HTTP. Test scaffolding changes only disposable local fixtures.

```sh
# WP_TESTS_DIR and DIGIFORGE_REVIEW_REPO must identify the disposable library and exact candidate.
vendor/bin/phpunit --no-configuration --bootstrap work/review-bootstrap.php work/PrintifyIntegrationReviewProbe.php
# Expected current exit: 1. Summary: 3 tests, 44 assertions, 3 failures.
```

Actual run: 2026-10-17; checkpoint STOP ALL/external lock ON in each probe. Probe SHA-256: `9f9ccf61fc138d5434a7752bed8cb62bf3e25f578b572f67915b06a5eee2fd35`. Local log SHA-256: `d231e4560b1d13260814c5c9945e81bebe78b3394d26222d127dbdecc649b729`. Full raw log is not committed; it includes local test paths and unrelated existing fixture migration warnings. These warnings did not prevent grant/exception setup, locked-posture checks, stub-count checks or evidence readback assertions from completing. No hosted combined-candidate run or live supplier behavior is claimed.

```php
<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyCatalogReadRepository as Reads;
use DigiForge\Database\Tables;
final class PrintifyIntegrationReviewProbe extends WP_UnitTestCase {
 private array $input;private int $owner;
 public function setUp():void {
  parent::setUp();if(!defined('DIGIFORGE_MAIN_SITE_URL'))define('DIGIFORGE_MAIN_SITE_URL',site_url());DigiForge\Core\Activator::activate();$this->owner=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($this->owner);global $wpdb;$now=gmdate('Y-m-d H:i:s');DigiForge\Core\Settings::protectProduction();$suffix=wp_rand(10000,99999);$businessKey='read-business-'.$suffix;$storeKey='read-store-'.$suffix;
  $wpdb->insert(Tables::businesses(),['business_key'=>$businessKey,'display_name'=>'Read fixture','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$business=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::stores(),['business_id'=>$business,'store_key'=>$storeKey,'display_name'=>'Read store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]);$store=(int)$wpdb->insert_id;
  $wpdb->insert(Tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]);
  $scope=['business_id'=>$businessKey,'store_id'=>$storeKey,'product_program'=>'PERSONALIZED_POD'];$repo=new DigiForge\Integrations\Repository();$integration=$repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>'catalog-fixture-'.$suffix,'status'=>'CONFIGURED','config'=>['catalog_read_scope'=>$scope]]);self::assertIsArray($integration);$id=$integration['id'];self::assertTrue($repo->storeSecret($id,'personal_access_token','offline-catalog-fixture-credential'));self::assertIsArray($repo->setEnabled($id,true));
  $this->input=['capability'=>'printify.catalog.read','integration_id'=>$id,'account_id'=>$this->owner]+$scope+['expires_at'=>gmdate('c',time()+1800),'max_requests'=>2,'requests'=>[['kind'=>'variant','blueprint_id'=>101,'provider_id'=>2,'variant_id'=>202]]];
 }
 private function unlocked():void {global $wpdb;foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false]as $key=>$v)$wpdb->query($wpdb->prepare('UPDATE '.Tables::settings().' SET setting_value=%s WHERE setting_key=%s',wp_json_encode($v),$key));}
 private function client(int &$calls):DigiForge\POD\PrintifyCatalogClient {return new DigiForge\POD\PrintifyCatalogClient(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/catalog/blueprints/101/print_providers/2/variants.json',$url);self::assertSame('GET',$args['method']);self::assertSame(0,$args['redirection']);self::assertSame(10,$args['timeout']);return ['response'=>['code'=>200],'body'=>'{"variants":[{"id":202,"title":"synthetic only","placeholders":[]},{"id":203}]}'];});}

 private function grant(Reads $r):array {$this->input['requests']=[['kind'=>'variants','blueprint_id'=>101,'provider_id'=>2]]; $g=$r->approve($this->input,'scoped-fixture-'.wp_generate_password(12,false));self::assertIsArray($g);return $g;}

 private function permitted(Reads $r):array {
  $g=$this->grant($r);$e=$r->approveSafetyException($g['sha256'],['capability'=>'printify.catalog.read','expires_at'=>gmdate('c',time()+600),'acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true],'probe-exception-'.wp_generate_password(12,false));self::assertIsArray($e);self::assertTrue(DigiForge\Core\Settings::safety_locked());return $g;
 }
 public function testDeletedGrantRevocationMustRemainDenied():void {
  $calls=0;$r=new Reads($this->client($calls));$g=$this->permitted($r);self::assertTrue($r->revoke($g['sha256']));self::assertWPError($r->preview($g['sha256'],0,'before-deletion'));
  global $wpdb;$count=$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='catalog_read_revoked'");self::assertGreaterThan(0,(int)$count);
  $wpdb->delete($wpdb->options,['option_name'=>'df_catalog_read_revoked_'.$g['sha256']]);
  $after=$r->preview($g['sha256'],0,'after-deletion');self::assertSame($count,$wpdb->get_var("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='catalog_read_revoked'"));
  self::assertSame(1,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
  self::assertWPError($after,'Retained grant revocation audit must deny when its option is lost.');
 }
 public function testDeletedDispatchMustNotRedispatchSameReservedRequest():void {
  $calls=0;$client=$this->client($calls);$r=new Reads($client);$g=$this->permitted($r);self::assertIsArray($r->preview($g['sha256'],0,'probe-dispatch'));
  $key=hash('sha256','probe-dispatch');global $wpdb;$wpdb->delete($wpdb->options,['option_name'=>'df_catalog_read_dispatch_'.$g['sha256'].'_'.$key]);
  $after=$client->scopedRead('offline-catalog-fixture-credential',$g['grant']['requests'][0],['grant_sha256'=>$g['sha256'],'request_index'=>0,'request_sha256'=>$key]);
  self::assertSame(2,$calls);self::assertTrue(DigiForge\Core\Settings::safety_locked());
  self::assertWPError($after,'Retained dispatch audit must deny a second dispatch after option loss.');
 }
 public function testMalformedVariantEnvelopeMustNotBecomeDraftEvidence():void {
  $client=new DigiForge\POD\PrintifyCatalogClient(static fn()=>['response'=>['code'=>200],'body'=>'{"unrelated":"synthetic unexpected field"}']);$r=new Reads($client);$g=$this->permitted($r);
  $out=$r->read($g['sha256'],0,'malformed-envelope');self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertIsArray($r->evidence($g['sha256'],hash('sha256','malformed-envelope')));
  self::assertWPError($out,'Variants response must contain a validated variants list.');
 }
}

```
