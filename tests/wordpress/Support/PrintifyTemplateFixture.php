<?php

declare(strict_types=1);

/** Test-only synthetic Printify evidence. It never represents supplier certification. */
function digiforge_test_printify_template(array $input): array|WP_Error
{
    $blueprint=(int)($input['provider_blueprint_id']??0);$provider=(int)($input['provider_id']??0);$ids=array_map('intval',(array)($input['variant_ids']??[]));$areas=(array)($input['print_areas']??[]);$environment=(string)($input['environment']??'sandbox');foreach($areas as &$area)if(!isset($area['region']))$area['region']='US';unset($area);
    if($blueprint<1||$provider<1||$ids===[]||$areas===[])return new WP_Error('fixture_invalid','Synthetic Printify catalog fixture requires exact identity and areas.');
    $persistence=new DigiForge\POD\PrintifyCatalogPersistence();$rows=[];
    foreach($ids as $variantId){$placeholders=[];foreach($areas as $area)$placeholders[]=['position'=>(string)$area['position'],'decoration_method'=>(string)$area['decoration_method'],'width'=>(int)$area['width_px'],'height'=>(int)$area['height_px']];$variant=['id'=>$variantId,'title'=>'Synthetic approved test variant','placeholders'=>$placeholders];$encoded=wp_json_encode($variant,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$observed=gmdate('Y-m-d\\TH:i:s\\Z');$variant['_digiforge_source_evidence']=['schema'=>'printify-variant-evidence-v1','endpoint_path'=>"/v1/catalog/blueprints/$blueprint/print_providers/$provider/variants.json",'response_sha256'=>hash('sha256',(string)$encoded),'observed_at'=>$observed,'variant_sha256'=>hash('sha256',(string)$encoded),'blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment];$saved=$persistence->persistVariant(['blueprint_id'=>$blueprint,'print_provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment,'attributes'=>$variant,'title'=>'Synthetic test blueprint','variant_label'=>'Synthetic test variant','base_cost'=>0,'currency'=>'USD','shipping_profile'=>[],'availability_state'=>'AVAILABLE','source_revision'=>'synthetic-fixture-v1','observed_at'=>$observed]);if(is_wp_error($saved))return $saved;$rows[]=$saved;}
    foreach(['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas'] as $reserved)unset($input[$reserved]);$template=DigiForge\POD\ProductionTemplateContract::fromPrintifyVariants($rows,$areas,$input);$template['shop_key']=(string)($input['shop_key']??'');return $template;
}
