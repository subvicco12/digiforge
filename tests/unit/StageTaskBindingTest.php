<?php
declare(strict_types=1);
use DigiForge\AI\StageTaskBinding;
use DigiForge\Launch\OpenAIClient;
use PHPUnit\Framework\TestCase;
require_once __DIR__."/../../includes/Launch/OpenAIClient.php";
if (is_file(__DIR__."/../../includes/AI/StageTaskBinding.php")) require_once __DIR__."/../../includes/AI/StageTaskBinding.php";
require_once __DIR__."/../../includes/AI/RoutingPolicy.php";
if (!class_exists('WP_Error')) { class WP_Error { public function __construct(...$args) {} } }
final class StageTaskBindingTest extends TestCase {
 private function task():array{return ['id'=>7,'environment'=>'production','status'=>'ACTIVE','required_capabilities'=>['json'],'min_quality_tier'=>2];}
 private function models():array{return [['id'=>11,'model_key'=>'reviewed-low','provider'=>'openai','environment'=>'production','enabled'=>true,'capabilities'=>['json'],'quality_tier'=>2,'fallback_order'=>1],['id'=>12,'model_key'=>'reviewed-high','provider'=>'openai','environment'=>'production','enabled'=>true,'capabilities'=>['json'],'quality_tier'=>3,'fallback_order'=>2]];}
 public function testEligibleRegisteredModelUsesExactReviewedProviderBinding():void {
  $result=StageTaskBinding::resolve(['task_id'=>7,'provider_models'=>[11=>'reviewed-provider-fixture',12=>'another-fixture']],$this->task(),$this->models());
  self::assertIsArray($result);self::assertSame(11,$result['model_id']);self::assertSame('reviewed-provider-fixture',$result['provider_model']);
 }
 public function testMissingSelectedModelBindingCannotSilentlyFallBack():void {
  $result=StageTaskBinding::resolve(['task_id'=>7,'provider_models'=>[12=>'another-fixture']],$this->task(),$this->models());self::assertInstanceOf(WP_Error::class,$result);
 }
 public function testEnvironmentIdentityAndProviderMismatchFailClosed():void {
  foreach(['environment','id','status']as $field){$task=$this->task();$task[$field]=$field==='id'?8:($field==='environment'?'sandbox':'DRAFT');self::assertInstanceOf(WP_Error::class,StageTaskBinding::resolve(['task_id'=>7,'provider_models'=>[11=>'fixture']],$task,$this->models()));}
  $models=$this->models();$models[0]['provider']='other';self::assertInstanceOf(WP_Error::class,StageTaskBinding::resolve(['task_id'=>7,'provider_models'=>[11=>'fixture']],$this->task(),$models));
 }
 public function testModelBindingChangesExactQuoteFingerprint():void {
  $first=OpenAIClient::generationContract('fixture',4000,false,true,'reviewed-first');$second=OpenAIClient::generationContract('fixture',4000,false,true,'reviewed-second');self::assertSame('reviewed-first',$first['model']);self::assertNotSame($first['request_sha256'],$second['request_sha256']);
 }
}
