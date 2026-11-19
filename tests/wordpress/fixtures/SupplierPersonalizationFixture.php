<?php
/** Synthetic retained catalog and real local supplier/design transitions; no provider evidence. */
final class SupplierPersonalizationFixture
{
 public static function snapshot(string $family):string {
  $mug=$family==='mug';[$b,$p,$v,$w,$h]=$mug?[5,7,9,2475,1155]:[6,8,10,2418,3018];
  $id=['Blueprint ID'=>(string)$b,'Provider ID'=>(string)$p,'Variant ID'=>(string)$v];
  $sections=['concepts'=>[['V2 ID'=>$mug?'DG2-001':'DG2-002','Physical Product'=>$mug?'PP-001':'PP-002']],
   'variants'=>[$id+['Title'=>'SYNTHETIC supplier fixture']],
   'print_areas'=>[$id+['Print Area'=>'front','Decoration'=>'sublimation','Width PX'=>(string)$w,'Height PX'=>(string)$h]]];
  $packet=['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'synthetic','name'=>'SYNTHETIC retained source; no provider certification','sha256'=>str_repeat('a',64),'modified_at'=>'2026-11-05T00:00:00Z']],'sections'=>[]];
  foreach($sections as $key=>$rows)$packet['sections'][$key]=['source_id'=>'synthetic','sheet'=>$key,'rows'=>$rows,'rows_sha256'=>DigiForge\POD\ProductIntelligence::rowsHash($rows)];
  $snapshot=(new DigiForge\POD\ProductIntelligenceRepository())->import($packet);if(is_wp_error($snapshot))throw new RuntimeException($snapshot->get_error_message());
  return $snapshot['snapshot_id'];
 }
 public static function start(string $family):array {
  $mug=$family==='mug';[$b,$p,$v]=$mug?[5,7,9]:[6,8,10];$snapshot=['snapshot_id'=>self::snapshot($family)];
  return self::call(['operation'=>'supplier_start','snapshot_id'=>$snapshot['snapshot_id'],'concept_id'=>$mug?'DG2-001':'DG2-002','product_family'=>$family,'blueprint_id'=>(string)$b,'provider_id'=>(string)$p,'variant_id'=>(string)$v]);
 }
 public static function approved(string $family):array {
  $start=self::start($family);$ctx=['supplier_workflow_hash'=>$start['supplier_workflow_hash'],'workflow_hash'=>$start['hash']];
  $response=json_decode(file_get_contents(__DIR__.'/../../fixtures/research/commercial-research.json'),true)['response'];
  foreach($response['scores'] as &$score)$score=null;unset($score);foreach($response['concepts'] as &$concept)$concept['source_ids']=['supplier_catalog'];unset($concept);
  $concepts=self::call($ctx+['operation'=>'concepts','method'=>'manual','response_json'=>wp_json_encode($response)]);
  $selected=self::call(array_replace($ctx,['operation'=>'select','workflow_hash'=>$concepts['hash'],'concept'=>'1']));$ctx['workflow_hash']=$selected['hash'];
  $approval=self::call($ctx+['operation'=>'design_approve_concept','sources_reviewed'=>'1','buyer_intent_reviewed'=>'1','gifting_occasion_reviewed'=>'1','note'=>'SYNTHETIC local source review']);
  $regions=[];foreach(['first_name','second_name','date'] as $i=>$name)$regions[]=['name'=>$name,'kind'=>'text','x'=>600,'y'=>300+$i*200,'width'=>1200,'height'=>120];
  $spec=self::call($ctx+['operation'=>'design_prepare','factory_hash'=>$approval['design_factory_hash'],'profile'=>$family==='mug'?'fl03_mug_11oz':'fl04_poster_8x10','layout_notes'=>'Fixed engineering vectors separate from native fields','typography'=>'Generic local proof only','font_license_reference'=>'SYNTHETIC fixture rights only','palette'=>'#123456','asset_requirements'=>'Original fixed SVG','native_regions_json'=>wp_json_encode($regions)]);
  $asset=self::call($ctx+['operation'=>'design_import','factory_hash'=>$spec['design_factory_hash'],'svg'=>file_get_contents(__DIR__.'/../../fixtures/design-factory/'.$family.'-original.svg'),'rights_note'=>'SYNTHETIC fixture rights only','source_note'=>'Committed engineering fixture; not commercial approval']);
  $review=self::call($ctx+['operation'=>'design_review','factory_hash'=>$asset['design_factory_hash'],'decision'=>'APPROVE','note'=>'SYNTHETIC local review','confirm'=>'1']+array_fill_keys(DigiForge\Research\DesignFactoryContract::REVIEW_CRITERIA,'1'));
  return $ctx+['factory_hash'=>$review['design_factory_hash']];
 }
 public static function call(array $post):array {
  $result=(new DigiForge\Portal\Shop2ProductWorkflowPanel())->process($post+['shop_key'=>'personalized_pod']);
  if(is_wp_error($result))throw new RuntimeException($result->get_error_message());return $result;
 }
}
