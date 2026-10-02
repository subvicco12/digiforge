<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use DigiForge\ProductFactory\AssetStorage;
use WP_Error;
/** Resolves the single governed customer delivery ZIP for an approved listing product. No external action occurs. */
final class EtsyCustomerDownloadResolver {
 public static function resolve(int $productVersionId): array|WP_Error {
  if($productVersionId<1)return self::error('product','A product version is required.');
  global $wpdb;
  $wpdb->last_error='';
  $bundle=$wpdb->get_row($wpdb->prepare('SELECT b.* FROM '.Tables::release_bundles().' b INNER JOIN '.Tables::production_plans()." p ON p.id=b.production_plan_id WHERE p.product_version_id=%d AND b.state='RELEASE_READY' ORDER BY b.id DESC LIMIT 1",$productVersionId),ARRAY_A);
  if(!empty($wpdb->last_error))return self::error('evidence_unavailable','RELEASE_READY customer bundle evidence could not be read.',503);
  if(!is_array($bundle))return self::error('bundle','No RELEASE_READY bundle exists for the approved listing product.');
  $wpdb->last_error='';
  $spec=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_specs()." WHERE product_version_id=%d AND asset_key='customer-package' AND asset_type='product_package' AND format='zip' ORDER BY id DESC LIMIT 1",$productVersionId),ARRAY_A);
  if(!empty($wpdb->last_error))return self::error('evidence_unavailable','Customer-package specification evidence could not be read.',503);
  if(!is_array($spec))return self::error('spec','The explicit customer-package ZIP specification is unavailable.');
  $wpdb->last_error='';
  $revision=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::asset_revisions()." WHERE asset_spec_id=%d AND state='APPROVED' ORDER BY id DESC LIMIT 1",(int)$spec['id']),ARRAY_A);
  if(!empty($wpdb->last_error))return self::error('evidence_unavailable','Approved customer-package revision evidence could not be read.',503);
  if(!is_array($revision))return self::error('revision','No approved customer-package revision is available.');
  $selected=EtsyCustomerDownloadSelector::select($bundle,$spec,$revision);
  if($selected instanceof WP_Error)return $selected;
  $path=AssetStorage::absolutePath((string)$selected['storage_reference']);
  if($path===null)return self::error('storage','Approved customer package bytes are unavailable in protected local storage.');
  $selected['absolute_path']=$path;
  $selected['release_bundle']=$bundle;
  return $selected;
 }
 private static function error(string $c,string $m,int $status=409):WP_Error{return new WP_Error('digiforge_etsy_customer_download_resolver_'.$c,$m,['status'=>$status]);}
}