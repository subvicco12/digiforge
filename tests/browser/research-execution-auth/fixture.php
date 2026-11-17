<?php
declare(strict_types=1);
/** Private loopback fixture: fixed simulator injected on server, never selected by POST. */
final class ResearchHttpFixture {
 public static function service():DigiForge\Research\ResearchExecution {
  $f=json_decode(file_get_contents(__DIR__.'/../../fixtures/research/commercial-research.json'),true);
  return new DigiForge\Research\ResearchExecution(new DigiForge\AI\OfflineResearchTransport(['status'=>'completed','payload'=>$f['response'],'model'=>'gpt-5.6-luna','usage'=>['input_tokens'=>10,'output_tokens'=>5,'total_tokens'=>15]]));
 }
 public static function checked($v):array {if(!is_array($v))throw new RuntimeException(is_wp_error($v)?$v->get_error_code():'fixture invalid');return $v;}
 public static function source():array {
  $r=new DigiForge\AI\Repository();$task=self::checked($r->createTask(['task_key'=>'http-'.wp_generate_uuid4(),'name'=>'Synthetic recorded research','environment'=>'production','required_capabilities'=>['text_generation','shop2_research_fixture']]));$model=self::checked($r->createModel(['model_key'=>'http-'.wp_generate_uuid4(),'provider'=>'openai','environment'=>'production','model_class'=>'text','capabilities'=>['text_generation','shop2_research_fixture'],'enabled'=>true]));
  self::checked((new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'personalized_pod','currency'=>'USD','budgets'=>['run'=>'0.040000','day'=>'0.100000','month'=>'0.200000'],'stages'=>['research'=>['limit'=>5,'monthly_budget'=>'0.200000','task_binding'=>['task_id'=>(int)$task['id'],'provider_models'=>[(int)$model['id']=>'gpt-5.6-luna']]]]]));
  $f=json_decode(file_get_contents(__DIR__.'/../../fixtures/research/commercial-research.json'),true);$f['research']['title']='SYNTHETIC HTTP recorded research '.wp_generate_uuid4();return self::checked((new DigiForge\Research\CommercialResearchRepository())->create($f['research']));
 }
}
