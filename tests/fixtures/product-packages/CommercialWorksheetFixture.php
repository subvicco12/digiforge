<?php
declare(strict_types=1);
/** Explicit synthetic conditional amounts; no provider/account/owner authority. */
final class CommercialWorksheetFixture {
 public static function input(array $binding=[]):array {
  $binding=$binding?:['shop_key'=>'personalized_pod','environment'=>'test','fixture_id'=>'FL-03','product_id'=>'6ae98d61eb5d7e24b80ca0a6','artwork_sha256'=>str_repeat('a',64),'template_fingerprint'=>null,'blueprint_id'=>null,'provider_id'=>null,'variant_ids'=>[]];
  $n=static fn($v)=>['value'=>$v,'source'=>$v===null?null:'synthetic'];
  return ['schema'=>'DF_SHOP2_COMMERCIAL_WORKSHEET_1','currency'=>'USD','binding'=>$binding,'as_of'=>'2026-11-14T12:00:00Z','sources'=>['synthetic'=>['reference'=>'https://example.invalid/synthetic-only','sha256'=>str_repeat('b',64),'classification'=>'OPERATOR_ASSUMPTION','observed_at'=>'2026-11-13','valid_until'=>'2026-11-15T00:00:00Z']],
   'revenue'=>['item_price_minor'=>$n(1799),'buyer_shipping_minor'=>$n(729),'buyer_tax_minor'=>$n(200)],
   'costs'=>array_map($n,['base_cost_minor'=>509,'supplier_shipping_minor'=>729,'surcharge_minor'=>40,'listing_fee_minor'=>20,'processing_fixed_minor'=>30,'conversion_minor'=>50,'regulatory_minor'=>0,'merchant_tax_minor'=>0,'other_cost_minor'=>0]),
   'rates'=>array_map($n,['transaction_bps'=>650,'processing_bps'=>500,'ads_bps'=>0,'refund_bps'=>300,'discount_bps'=>0]),'target_margin_bps'=>2000];
 }
}
