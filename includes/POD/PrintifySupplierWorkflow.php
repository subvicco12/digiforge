<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Read-only supplier selection review. Never constructs or approves a production package. */
final class PrintifySupplierWorkflow {
 public static function review(array $packet,int $blueprint,int $provider,int $variant):array {
  foreach([$blueprint,$provider,$variant] as $id)if($id<1||$id>2147483647)throw new \InvalidArgumentException('Positive bounded supplier identifiers required.');
  $packet=ProductIntelligence::validate($packet);$evidence=[];$sources=array_column($packet['sources'],null,'id');
  $fields=['variants'=>['Title','SKU','Available','Options','Raw Cost Evidence','Currency','Production Cost','Tax Amount','Cost Status'],'print_areas'=>['Print Area','Width PX','Height PX','DPI','Bleed','Safe Zone','Geometry Status'],'size_guides'=>['Size / Variant','Measurement','Value','Unit','Range To','Evidence'],'shipping'=>['Variant ID','Service','Country / Zone','First Item Amount','Additional Item Amount','Currency','Rate Units','Handling From','Handling To','Evidence']];
  foreach($fields as $section=>$columns){$evidence[$section]=[];foreach($packet['sections'][$section]['rows']??[] as $row){
   if(($row['Blueprint ID']??null)!==(string)$blueprint)continue;
   if($section!=='size_guides'&&($row['Provider ID']??null)!==(string)$provider)continue;
   if(in_array($section,['variants','print_areas'],true)&&($row['Variant ID']??null)!==(string)$variant)continue;
   if($section==='shipping'&&isset($row['Variant ID'])&&$row['Variant ID']!==(string)$variant)continue;
   $source=$sources[$row['_source_id']??$packet['sections'][$section]['source_id']];$facts=[];foreach($columns as $column)$facts[$column]=$row[$column]??null;
   $evidence[$section][]=['facts'=>$facts,'source_id'=>$source['id'],'source_sha256'=>$source['sha256'],'source_modified_at'=>$source['modified_at'],'row_sha256'=>ProductIntelligence::rowsHash([$row]),'approval_status'=>'UNREVIEWED'];
  }}
  if($evidence['variants']===[])throw new \InvalidArgumentException('Selected variant has no exact blueprint/provider evidence.');
  $gaps=['SOURCE_AUTHENTICITY_UNVERIFIED','PHYSICAL_TEMPLATE_CERTIFICATION_REQUIRED','APPROVED_NATIVE_PERSONALIZATION_REQUIRED','APPROVED_DESIGN_AND_MOCKUP_REQUIRED','PRODUCT_PACKAGE_REVIEW_REQUIRED','PRODUCTION_COST_AND_TAX_UNVERIFIED'];
  foreach(['print_areas','size_guides','shipping'] as $section)if($evidence[$section]===[])$gaps[]=strtoupper($section).'_EVIDENCE_MISSING';
  $quotes=array_filter($evidence['shipping'],static fn($row)=>($row['facts']['Variant ID']??null)===(string)$variant&&($row['facts']['Country / Zone']??null)!==null&&($row['facts']['First Item Amount']??null)!==null);
  if($quotes===[])$gaps[]='DESTINATION_RATES_MISSING';
  if(count(array_unique(array_map(static fn($r)=>ProductIntelligence::rowsHash([$r['facts']]),$evidence['variants'])))>1)$gaps[]='VARIANT_OBSERVATIONS_REQUIRE_RECONCILIATION';
  return ['shop_key'=>'personalized_pod','blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variant,'state'=>'REVIEW_REQUIRED','evidence'=>$evidence,'gaps'=>$gaps,'production_cost'=>null,'tax_amount'=>null,'destination_shipping_rate'=>null,'production_authority'=>false,'external_execution_authorized'=>false,'finance_authority'=>false];
 }
}
