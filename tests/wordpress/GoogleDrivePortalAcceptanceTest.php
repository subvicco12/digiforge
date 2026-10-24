<?php
declare(strict_types=1);
require_once __DIR__.'/ExternalDocumentAdmissionTest.php';
use DigiForge\DigitalFactory\{DriveGoogleReadModel,DriveGoogleOAuth,DriveWordPressGrantStore,DriveTransferRequest};
use DigiForge\Portal\GoogleDrivePanel;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;

final class GoogleDrivePortalAcceptanceTest extends WP_UnitTestCase
{
 private int $network=0;private Closure $deny;private array $paths=[];private array $keys=[];
 protected function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);$this->deny=function(){++$this->network;return new WP_Error('no_google');};add_filter('pre_http_request',$this->deny);}
 protected function tearDown():void{remove_filter('pre_http_request',$this->deny);foreach($this->paths as $p)@unlink($p);global $wpdb;foreach($this->keys as $k)$wpdb->delete($wpdb->options,['option_name'=>$k]);$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');self::assertSame(0,$this->network);self::assertTrue(Settings::get('stop_all',true));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();}
 private function source():array{
  $r=(new ReflectionMethod(ExternalDocumentAdmissionTest::class,'documentRequest'))->invoke(new ExternalDocumentAdmissionTest());$this->paths=[$r['source_path'],$r['export_path']];
  $a=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($r);self::assertNotWPError($a);$repo=new DigiForge\Production\Repository();$revision=$repo->addRevision($a['revision_input']);self::assertNotWPError($revision);
  self::assertNotWPError($repo->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$a['evidence']+['binding'=>$a['binding']]]));
  return ['id'=>hash('sha256',wp_generate_uuid4()),'installation_sha256'=>hash('sha256',site_url()),'environment'=>wp_get_environment_type(),'shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'actor_id'=>get_current_user_id(),'product_id'=>$r['product_id'],'product_version_id'=>$r['product_version_id'],'revision_id'=>(int)$revision['id'],'source_sha256'=>$r['source_sha256']];
 }
 private function plan(array $b):void{
  $key=DriveGoogleOAuth::key('operation','workflow:plan:'.$b['id']);$this->keys[]=$key;self::assertTrue((new DriveWordPressGrantStore())->compareAndSwap($key,[],['binding'=>$b,'state'=>'CONNECTED','flow_sha256'=>str_repeat('f',64),'connection'=>['connection_id'=>'synthetic-connection','account_id'=>'synthetic-account','generation'=>str_repeat('a',64)]]));
 }
 private function html(string $shop,?array $data=null):string{ob_start();try{(new GoogleDrivePanel($data===null?null:fn()=>$data))->render($shop);return ob_get_contents();}finally{ob_end_clean();}}
 public function testActualScopedSourceAndPlanAreVisibleUnderHoldWithoutWritesOrSecrets():void{
  $b=$this->source();$this->plan($b);global $wpdb;$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::jobs());$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->options);
  $r=(new DriveGoogleReadModel())->snapshot('digital',$b['id']);self::assertSame('AVAILABLE',$r['state']);self::assertContains($b['revision_id'],array_column($r['sources'],'revision_id'));self::assertFalse($r['sources'][0]['enabled']);self::assertSame($b['id'],$r['plans'][0]['id']);self::assertFalse($r['plans'][0]['actions']['queue']);
  $get=$_GET;$_GET['df_google_plan']=$b['id'];try{$html=$this->html('digital');}finally{$_GET=$get;}
  self::assertStringContainsString('Connect Google for this source',$html);self::assertStringContainsString($b['source_sha256'],$html);self::assertStringContainsString('df_confirm_google_action',$html);self::assertStringNotContainsString('synthetic-account',$html);self::assertStringNotContainsString(str_repeat('f',64),$html);
  self::assertSame($count,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::jobs()));self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->options));
  $foreign=$b;$foreign['id']=hash('sha256','foreign-'.$b['id']);$foreign['actor_id']=self::factory()->user->create(['role'=>'administrator']);$this->plan($foreign);
  $r=(new DriveGoogleReadModel())->snapshot('digital',$foreign['id']);self::assertNotContains($foreign['id'],array_column($r['plans'],'id'));
  self::assertSame([], (new DriveGoogleReadModel())->snapshot('personalized_pod')['plans']);self::assertStringNotContainsString($b['source_sha256'],$this->html('personalized_pod'));
 }
 public function testNativeHandlersDenyUnconfirmedAndConfirmedHeldQueueRequests():void{
  $post=$_POST;$request=$_REQUEST;$method=$_SERVER['REQUEST_METHOD']??null;
  $die=static fn()=>static function(){throw new RuntimeException('google_portal_denied');};add_filter('wp_die_handler',$die);
  global $wpdb;$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::jobs());
  try{foreach([null,'1'] as $confirmation){$_SERVER['REQUEST_METHOD']='POST';$_POST=['plan_id'=>str_repeat('a',64),'_wpnonce'=>wp_create_nonce('digiforge_google_queue')];if($confirmation!==null)$_POST['df_confirm_google_action']=$confirmation;$_REQUEST=$_POST;
   try{(new DigiForge\DigitalFactory\DriveGoogleWordPress())->queue();self::fail('Held request must deny.');}catch(RuntimeException $e){self::assertSame('google_portal_denied',$e->getMessage());}
  }}finally{remove_filter('wp_die_handler',$die);$_POST=$post;$_REQUEST=$request;if($method===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$method;}
  self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::jobs()));
 }
 public function testBrowserFixturesHaveExactControlsAndNoSecretProjection():void{
  $a=['queue'=>true,'refresh'=>true,'renew'=>true,'original_upload'=>false,'native_conversion'=>false];
  $p=['id'=>str_repeat('a',64),'product_id'=>9,'version_id'=>5,'revision_id'=>12,'source_sha256'=>str_repeat('b',64),'state'=>'QUEUED','connection'=>'ACTIVE','grant_state'=>'CURRENT','jobs'=>[['id'=>77,'stage'=>'folder','state'=>'SUCCESS','scheduler'=>'COMPLETE']],'actions'=>$a];
  $r=['state'=>'AVAILABLE','sources'=>[['product_id'=>9,'version_id'=>5,'revision_id'=>12,'source_sha256'=>str_repeat('b',64),'enabled'=>true]],'plans'=>[$p],'truncated'=>false];
  $fixtures=['ready'=>$this->html('digital',$r)];$r['sources'][0]['enabled']=false;$r['plans'][0]['actions']=array_fill_keys(array_keys($a),false);$fixtures['held']=$this->html('digital',$r);
  $r['plans'][0]['state']='UNKNOWN';$r['plans'][0]['actions']=$a;$r['plans'][0]['actions']['queue']=false;$r['plans'][0]['actions']['original_upload']=true;$fixtures['unknown']=$this->html('digital',$r);
  $r['plans'][0]['state']='TECHNICAL_QA_COMPLETE';$r['plans'][0]['actions']=array_fill_keys(array_keys($a),false);$fixtures['done']=$this->html('digital',$r);$fixtures['foreign']=$this->html('personalized_pod',$r);$fixtures['unavailable']=$this->html('digital',['state'=>'UNAVAILABLE']);
  self::assertStringContainsString('Authorize source-bound transfer',$fixtures['ready']);self::assertStringContainsString('Reconcile original upload by read only',$fixtures['unknown']);self::assertStringContainsString('Commercial approval',$fixtures['done']);self::assertStringContainsString('PENDING',$fixtures['done']);self::assertStringNotContainsString('plan_id',$fixtures['foreign']);
  $dir=getenv('DF_GOOGLE_PORTAL_FIXTURES');if(is_string($dir)&&$dir!==''){self::assertTrue(is_dir($dir));foreach($fixtures as $name=>$html)self::assertNotFalse(file_put_contents($dir.'/'.$name.'.html',$html));}
 }
}
