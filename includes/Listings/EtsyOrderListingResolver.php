<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;

/** Resolves an Etsy listing identity to one unique approved DigiForge listing/product. */
final class EtsyOrderListingResolver {
 /** @return array<string,int>|null */
 public static function resolve(string $etsyListingId,string $shopReference):?array {
  if(!ctype_digit($etsyListingId)||(int)$etsyListingId<1||!ctype_digit($shopReference)||(int)$shopReference<1)return null;
  global $wpdb;
  $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT l.id,l.product_version_id FROM {$wpdb->prefix}digiforge_etsy_operations o INNER JOIN ".Tables::etsy_intents()." i ON i.id=o.intent_id INNER JOIN ".Tables::listings()." l ON l.id=i.listing_id WHERE o.operation_type='CREATE_DRAFT' AND o.state='CONFIRMED_SUCCESS' AND o.external_reference=%s AND o.shop_reference=%s AND i.state='APPROVED_INTENT' AND l.state='APPROVED' LIMIT 2",$etsyListingId,$shopReference),ARRAY_A);
  if(!is_array($rows)||count($rows)!==1)return null;
  return ['listing_id'=>(int)$rows[0]['id'],'product_version_id'=>(int)$rows[0]['product_version_id']];
 }
}
