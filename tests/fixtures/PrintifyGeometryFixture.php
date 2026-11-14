<?php
declare(strict_types=1);
/** Synthetic documented provider response and derived provenance; isolated tests only. */
final class PrintifyGeometryFixture {
 public static function catalog(int $blueprint,int $provider,int $variant,int $width=1200,int $height=1600):array {
  $attributes=['id'=>$variant,'title'=>'Synthetic size','options'=>['size'=>'fixture'],'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>$width,'height'=>$height]]];
  $raw=wp_json_encode($attributes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$observed=gmdate('c');
  $attributes['_digiforge_source_evidence']=['schema'=>'printify-variant-evidence-v1','endpoint_path'=>"/v1/catalog/blueprints/$blueprint/print_providers/$provider/variants.json",'response_sha256'=>hash('sha256',wp_json_encode(['variants'=>[json_decode($raw,true)]])),'observed_at'=>$observed,'variant_sha256'=>hash('sha256',$raw),'blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variant,'environment'=>'sandbox'];
  return ['provider'=>'printify','environment'=>'sandbox','provider_product_key'=>(string)$blueprint,'provider_variant_key'=>"$provider:$variant",'attributes'=>$attributes,'source_revision'=>'isolated-response-v1'];
 }
 public static function candidate(array $row,string $id,int $width=1200,int $height=1600):array {
  return DigiForge\POD\ProductionTemplateContract::fromPrintifyCatalog($row,[['position'=>'front','decoration_method'=>'dtg','width_px'=>$width,'height_px'=>$height,'region'=>'US']],['template_id'=>$id,'template_version'=>1,'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'NAME_MONOGRAM','template_status'=>'VALIDATED']);
 }
}
