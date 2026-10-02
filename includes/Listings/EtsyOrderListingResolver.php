<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Resolves an Etsy listing identity to one unique approved DigiForge listing/product. */
final class EtsyOrderListingResolver {
 /** @return array<string,int>|WP_Error|null */
 public static function resolve(string $etsyListingId,string $shopReference):array|WP_Error|null {
  if(!ctype_digit($etsyListingId)||(int)$etsyListingId<1||!ctype_digit($shopReference)||(int)$shopReference<1)return null;
  global $wpdb;
  $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT l.id,l.product_version_id FROM {$wpdb->prefix}digiforge_etsy_operations o INNER JOIN ".Tables::etsy_intents()." i ON i.id=o.intent_id INNER JOIN ".Tables::listings()." l ON l.id=i.listing_id WHERE o.operation_type='CREATE_DRAFT' AND o.state='CONFIRMED_SUCCESS' AND o.external_reference=%s AND o.shop_reference=%s AND i.state='APPROVED_INTENT' AND l.state='APPROVED' LIMIT 2",$etsyListingId,$shopReference),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('digiforge_etsy_order_listing_evidence_unavailable','Confirmed Etsy listing resolution evidence could not be read.',['status'=>503]);
  if(!is_array($rows)||count($rows)!==1)return null;
  return ['listing_id'=>(int)$rows[0]['id'],'product_version_id'=>(int)$rows[0]['product_version_id']];
 }
}
