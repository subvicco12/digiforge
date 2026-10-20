<?php
declare(strict_types=1);
final class AiBackgroundMeteringTest extends WP_UnitTestCase {
 public function setUp():void {
  parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));global $wpdb;$t=DigiForge\Database\Tables::class;$now=current_time('mysql',true);
  $wpdb->update($t::integrations(),['enabled'=>0],['provider'=>'ai']);$wpdb->insert($t::integrations(),['provider'=>'ai','environment'=>'production','connection_key'=>'fixture-'.wp_generate_uuid4(),'display_name'=>'OFFLINE HTTP FIXTURE','status'=>'CONFIGURED','enabled'=>1,'config'=>'{}','created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]);$id=(int)$wpdb->insert_id;
  $wpdb->insert($t::integration_secrets(),['integration_id'=>$id,'secret_name'=>'api_key','ciphertext'=>DigiForge\Integrations\CredentialVault::encrypt('fixture',DigiForge\Integrations\Repository::secretContext($id,'api_key')),'fingerprint'=>'fixture','created_at'=>$now,'updated_at'=>$now]);
 }
 private function body(string $id):array{return ['id'=>$id,'status'=>'completed','model'=>'fixture-model','output_text'=>'{"fixture":true}','usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]];}
 public function testImmediateCompletedBackgroundPostRetainsMetering():void {
  $id='resp_'.str_replace('-','',wp_generate_uuid4());$body=$this->body($id);$filter=static fn()=>['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];add_filter('pre_http_request',$filter);
  try{$r=(new DigiForge\Launch\OpenAIClient())->startBackgroundDevelop('offline fixture');}finally{remove_filter('pre_http_request',$filter);}
  self::assertFalse(is_wp_error($r));self::assertSame('fixture-model',$r['model']??null);self::assertSame(30,$r['usage']['total_tokens']??null);self::assertSame(['fixture'=>true],$r['payload']??null);
 }
 public function testPollingRejectsDifferentReturnedProviderIdentity():void {
  $body=$this->body('resp_different');$filter=static fn()=>['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];add_filter('pre_http_request',$filter);
  try{$r=(new DigiForge\Launch\OpenAIClient())->retrieveBackground('resp_expected');}finally{remove_filter('pre_http_request',$filter);}
  self::assertTrue(is_wp_error($r));self::assertSame('digiforge_launch_ai_identity',$r->get_error_code());
 }
 public function testCompletedPostThenEquivalentPollPreservesBothRawReceipts():void {
  $id='resp_'.str_replace('-','',wp_generate_uuid4());$body=$this->body($id);$json=wp_json_encode($body);$pollBody=array_reverse($body,true);$pollBody['usage']=array_reverse($body['usage'],true);$poll=json_encode($pollBody,JSON_PRETTY_PRINT);$calls=0;
  $filter=static function()use(&$calls,$json,$poll){return ['headers'=>[],'response'=>['code'=>200],'body'=>++$calls===1?$json:$poll,'cookies'=>[]];};add_filter('pre_http_request',$filter);
  try{
   $client=new DigiForge\Launch\OpenAIClient();$post=$client->startBackgroundDevelop('offline fixture');$shop='poll-'.wp_rand();$gov=new DigiForge\AI\ShopAiGovernanceRepository();$gov->savePolicy(['shop_key'=>$shop,'stages'=>['develop'=>['limit'=>8]]]);$usage=$gov->reserveGeneration($shop,'develop','poll-'.wp_generate_uuid4());$repo=new DigiForge\AI\ProviderEvidenceRepository();self::assertFalse(is_wp_error($repo->recordOutcome((int)$usage['id'],$shop,$post)));
   $done=$client->retrieveBackground($id);self::assertFalse(is_wp_error($done));self::assertSame(30,$done['usage']['total_tokens']);
   global $wpdb;foreach([$json,$poll] as $raw)self::assertNotNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','digiforge_ai_evidence_observation_'.$usage['id'].'_'.hash('sha256',$raw))));
  }finally{remove_filter('pre_http_request',$filter);}
 }

 public function testInvalidStructuredOutputStillPreservesProviderMetering():void {
  $id='resp_'.str_replace('-','',wp_generate_uuid4());$body=$this->body($id);$body['output_text']='not JSON';$filter=static fn()=>['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];add_filter('pre_http_request',$filter);
  $shop='invalid-'.wp_rand();$gov=new DigiForge\AI\ShopAiGovernanceRepository();$gov->savePolicy(['shop_key'=>$shop,'stages'=>['develop'=>['limit'=>8]]]);$method=new ReflectionMethod(DigiForge\AI\GovernedGeneration::class,'execute');
  try{$error=$method->invoke(new DigiForge\AI\GovernedGeneration(),$shop,'develop','invalid-'.wp_generate_uuid4(),null,fn()=>(new DigiForge\Launch\OpenAIClient())->develop('offline invalid output'));}finally{remove_filter('pre_http_request',$filter);}
  self::assertTrue(is_wp_error($error));self::assertSame('digiforge_launch_ai_invalid_json',$error->get_error_code());$data=$error->get_error_data();self::assertArrayHasKey('ai_usage_id',$data);self::assertFalse($data['retry_permitted']);$state=(new DigiForge\AI\ProviderEvidenceRepository())->snapshot((int)$data['ai_usage_id'],$shop);self::assertSame(30,$state['metering']['usage']['total_tokens']);self::assertSame('AWAITING_PROVIDER_CHARGE',$state['cost_state']);
 }
 public function testIncompleteBackgroundPollPreservesBoundMeteringAndNeverRetries():void {
  $id='resp_'.str_replace('-','',wp_generate_uuid4());$shop='failed-'.wp_rand();$gov=new DigiForge\AI\ShopAiGovernanceRepository();$gov->savePolicy(['shop_key'=>$shop,'stages'=>['develop'=>['limit'=>8]]]);$usage=$gov->reserveGeneration($shop,'develop','failed-'.wp_generate_uuid4());$repo=new DigiForge\AI\ProviderEvidenceRepository();$repo->recordOutcome((int)$usage['id'],$shop,['response_id'=>$id,'status'=>'queued']);$body=$this->body($id);$body['status']='incomplete';$body['output_text']='partial';$filter=static fn()=>['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];add_filter('pre_http_request',$filter);
  try{$error=(new DigiForge\Launch\OpenAIClient())->retrieveBackground($id);}finally{remove_filter('pre_http_request',$filter);}
  self::assertTrue(is_wp_error($error));self::assertSame('digiforge_launch_ai_incomplete',$error->get_error_code());self::assertFalse($error->get_error_data()['retry_permitted']);$state=$repo->snapshot((int)$usage['id'],$shop);self::assertSame('incomplete',$state['metering']['provider_status']);self::assertSame(30,$state['metering']['usage']['total_tokens']);
 }

 public function testResearchQuoteFingerprintIncludesActualWebSearchBody():void {
  $body=$this->body('resp_quote_research');$captured='';$filter=static function($pre,array $args)use(&$captured,$body){$captured=(string)$args['body'];return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body),'cookies'=>[]];};add_filter('pre_http_request',$filter,false,2);
  try{self::assertNotWPError((new DigiForge\Launch\OpenAIClient())->research('quoted research'));}finally{remove_filter('pre_http_request',$filter);}
  $contract=DigiForge\Launch\OpenAIClient::generationContract('quoted research',4000,false,true);self::assertSame(hash('sha256',$captured),$contract['request_sha256']);self::assertSame([['type'=>'web_search']],json_decode($captured,true)['tools']);
 }
}
