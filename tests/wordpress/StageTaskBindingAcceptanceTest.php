<?php
declare(strict_types=1);
final class StageTaskBindingAcceptanceTest extends WP_UnitTestCase {
 private string $shop;
 private array $binding;
 protected function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
  $this->shop='routing-fixture-'.wp_rand();$repo=new DigiForge\AI\Repository();
  $task=$repo->createTask(['task_key'=>'route-'.wp_rand(),'name'=>'Offline task fixture','environment'=>'production','required_capabilities'=>['json'],'min_quality_tier'=>2]);self::assertNotWPError($task);
  $model=$repo->createModel(['model_key'=>'route-'.wp_rand(),'provider'=>'openai','environment'=>'production','capabilities'=>['json'],'quality_tier'=>2,'enabled'=>true,'fallback_order'=>0]);self::assertNotWPError($model);
  // Isolate registered routing candidates from any unrelated acceptance fixture.
  global $wpdb;$wpdb->query($wpdb->prepare('UPDATE '.DigiForge\Database\Tables::ai_models().' SET enabled=0 WHERE id<>%d',$model['id']));
  $this->binding=['task_id'=>$task['id'],'provider_models'=>[$model['id']=>'reviewed-provider-fixture']];
 }
 private function policy(?array $binding=null):array|WP_Error {
  return (new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>$this->shop,'currency'=>'USD','stages'=>['develop'=>['limit'=>4,'monthly_budget'=>'2','task_binding'=>$binding??$this->binding]]]);
 }
 public function testRegisteredBindingProjectsAndExactReviewedQuoteReservesSelectedRequest():void {
  self::assertNotWPError($this->policy());$route=DigiForge\AI\StageTaskBinding::load($this->binding);self::assertNotWPError($route);
  $projection=(new DigiForge\AI\ShopAiGovernanceRepository())->evaluate($this->shop);self::assertSame($this->binding,$projection['stages']['develop']['task_binding']);
  $contract=DigiForge\Launch\OpenAIClient::generationContract('offline routed fixture',8000,false,false,$route['provider_model']);
  $quote=['provider'=>'openai','quote_id'=>'route-'.wp_generate_uuid4(),'model'=>$contract['model'],'request_sha256'=>$contract['request_sha256'],'currency'=>'USD','maximum_charge'=>'0.25','inclusive_all_charges'=>true,'valid_from'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'valid_until'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)];
  $money=new DigiForge\AI\MonetaryReservationRepository();self::assertNotWPError($money->reviewQuote($this->shop,(string)wp_json_encode($quote),'CONFIRM_PROVIDER_QUOTE'));
  $exact=$money->findQuote($this->shop,$contract);self::assertNotWPError($exact);
  $usage=(new DigiForge\AI\ShopAiGovernanceRepository())->reserveGeneration($this->shop,'develop','routed-attempt',null,$exact,$contract);self::assertNotWPError($usage);self::assertSame($route['model_key'],$usage['model_key']);
  $other=DigiForge\Launch\OpenAIClient::generationContract('offline routed fixture',8000,false,false,'unreviewed-provider-fixture');self::assertWPError($money->findQuote($this->shop,$other));
  self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testExactSelectedModelIsUsedByInterceptedProviderEnvelope():void {
  $route=DigiForge\AI\StageTaskBinding::load($this->binding);self::assertNotWPError($route);global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
  $wpdb->update($t::integrations(),['enabled'=>0],['provider'=>'ai']);self::assertSame(1,$wpdb->insert($t::integrations(),['provider'=>'ai','environment'=>'production','connection_key'=>'route-'.wp_generate_uuid4(),'display_name'=>'OFFLINE HTTP FIXTURE','status'=>'CONFIGURED','enabled'=>1,'config'=>'{}','created_at'=>$now,'updated_at'=>$now]));$id=(int)$wpdb->insert_id;
  self::assertSame(1,$wpdb->insert($t::integration_secrets(),['integration_id'=>$id,'secret_name'=>'api_key','ciphertext'=>DigiForge\Integrations\CredentialVault::encrypt('fixture',DigiForge\Integrations\Repository::secretContext($id,'api_key')),'fingerprint'=>'fixture','created_at'=>$now,'updated_at'=>$now]));
  $sent=null;$filter=static function($pre,$args,$url)use(&$sent){$sent=json_decode($args['body'],true);return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode(['id'=>'resp_fixture','status'=>'completed','model'=>$sent['model'],'output_text'=>'{"fixture":true}','usage'=>['input_tokens'=>1,'output_tokens'=>1,'total_tokens'=>2]]),'cookies'=>[]];};add_filter('pre_http_request',$filter,10,3);
  try{$response=(new DigiForge\Launch\OpenAIClient())->develop('offline routed fixture',$route['provider_model']);}finally{remove_filter('pre_http_request',$filter,10);}
  self::assertNotWPError($response);self::assertSame('reviewed-provider-fixture',$sent['model']);self::assertSame(DigiForge\Launch\OpenAIClient::generationContract('offline routed fixture',8000,false,false,$route['provider_model'])['request_sha256'],hash('sha256',(string)wp_json_encode($sent)));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 public function testInvalidRegisteredBindingAndRequestMismatchFailBeforeReservation():void {
  self::assertNotWPError($this->policy());$invalid=$this->binding;$invalid['task_id']=PHP_INT_MAX;self::assertWPError(DigiForge\AI\StageTaskBinding::load($invalid));
  $result=(new DigiForge\AI\ShopAiGovernanceRepository())->reserveGeneration($this->shop,'develop','wrong-selected-request',null,null,['model'=>'other-fixture']);self::assertWPError($result);self::assertSame('ai_task_binding_changed',$result->get_error_code());
  global $wpdb;self::assertSame('0',(string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::shop_ai_usage().' WHERE shop_key=%s',$this->shop)));
 }
 public function testTaskBindingPolicyRequiresHumanReviewAndStrictSchema():void {
  foreach([['task_id'=>'7','provider_models'=>[1=>'fixture']],['task_id'=>7,'provider_models'=>[]],['task_id'=>7,'provider_models'=>[1=>' fixture ']]]as $invalid)self::assertWPError($this->policy($invalid));
  wp_set_current_user(0);self::assertWPError($this->policy());
 }
}
