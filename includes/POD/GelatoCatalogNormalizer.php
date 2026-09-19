<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Pure local Gelato catalog normalization boundary.
 * Accepts already-fetched provider evidence; performs no HTTP and no mutation.
 */
final class GelatoCatalogNormalizer
{
    /** @return array<string,mixed>|WP_Error */
    public static function normalize(array $item,string $environment,string $observedAt):array|WP_Error
    {
        $environment=sanitize_key($environment);
        if(!in_array($environment,['sandbox','test','production'],true))
            return new WP_Error('digiforge_gelato_environment','Invalid Gelato environment.',['status'=>400]);
        $product=sanitize_text_field((string)($item['product_id']??$item['productUid']??''));
        $variant=sanitize_text_field((string)($item['variant_id']??$item['variantUid']??''));
        if($product==='')
            return new WP_Error('digiforge_gelato_product','Gelato product identity is required.',['status'=>502]);
        $currency=strtoupper(trim((string)($item['currency']??'USD')));
        if(preg_match('/^[A-Z]{3}$/',$currency)!==1)
            return new WP_Error('digiforge_gelato_currency','Gelato currency evidence is invalid.',['status'=>502]);
        $cost=$item['base_cost']??$item['price']??0;
        if(!is_numeric($cost)||(float)$cost<0||!is_finite((float)$cost))
            return new WP_Error('digiforge_gelato_cost','Gelato cost evidence is invalid.',['status'=>502]);
        return ['provider'=>'gelato','environment'=>$environment,'provider_product_key'=>$product,'provider_variant_key'=>$variant,'title'=>sanitize_text_field((string)($item['title']??'')),'variant_label'=>sanitize_text_field((string)($item['variant_title']??'')),'currency'=>$currency,'base_cost'=>round((float)$cost,4),'availability_state'=>!empty($item['available'])?'AVAILABLE':'UNKNOWN','observed_at'=>sanitize_text_field($observedAt),'external_execution_performed'=>false];
    }
}
