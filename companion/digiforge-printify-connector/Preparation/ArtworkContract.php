<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
use DigiForge\Research\DesignFactoryContract;
/** Offline technical validation only. PNG only: <=2 MiB, <=40M decoded bytes.
 * JPEG, SVG, indexed/interlaced PNG and unsupported chunks are deliberately rejected.
 * Receipt references are untrusted until the host resolves its existing evidence.
 */
final class ArtworkContract
{
 public static function inspect(string $bytes,array $evidence,ShopBinding $scope):array
 {
  self::keys($evidence,['asset_sha256','rights_receipt_sha256','ownership_receipt_sha256','geometry_receipt_sha256','shop_key','integration_id','provider_shop_id','geometry','content_bounds']);
  foreach(['asset_sha256','rights_receipt_sha256','ownership_receipt_sha256','geometry_receipt_sha256'] as $key)if(!is_string($evidence[$key])||!preg_match('/^[a-f0-9]{64}$/D',$evidence[$key]))self::invalid();
  if($evidence['shop_key']!==$scope->shopKey||$evidence['integration_id']!==$scope->integrationId||$evidence['provider_shop_id']!==$scope->providerShopId||!hash_equals($evidence['asset_sha256'],hash('sha256',$bytes)))self::invalid();
  $g=$evidence['geometry'];$c=$evidence['content_bounds'];if(!is_array($g)||!is_array($c))self::invalid();
  self::keys($g,['width_px','height_px','dpi','safe_zone','bleed_px']);
  foreach(['width_px','height_px'] as $key)if(!is_int($g[$key])||$g[$key]<1||$g[$key]>40000000)self::invalid();
  if($g['dpi']!==300||!is_int($g['bleed_px'])||$g['bleed_px']<0||!is_array($g['safe_zone']))self::invalid();
  $safe=$g['safe_zone'];self::rectangle($safe);self::rectangle($c);$bleed=$g['bleed_px'];
  // Subtraction avoids overflow for hostile PHP_INT_MAX coordinates.
  if($bleed>$g['width_px']||$bleed>$g['height_px']||$safe['x']<$bleed||$safe['y']<$bleed||$safe['x']>$g['width_px']-$bleed||$safe['y']>$g['height_px']-$bleed||$safe['width']>$g['width_px']-$bleed-$safe['x']||$safe['height']>$g['height_px']-$bleed-$safe['y'])self::invalid();
  if($c['x']<$safe['x']||$c['y']<$safe['y']||$c['x']-$safe['x']>$safe['width']||$c['y']-$safe['y']>$safe['height']||$c['width']>$safe['width']-($c['x']-$safe['x'])||$c['height']>$safe['height']-($c['y']-$safe['y']))self::invalid();
  $r=DesignFactoryContract::raster($bytes,['geometry'=>['width_px'=>$g['width_px'],'height_px'=>$g['height_px']]]);
  return ['sha256'=>$r['sha256'],'mime_type'=>$r['mime_type'],'width_px'=>$r['width_px'],'height_px'=>$r['height_px'],'dpi'=>$r['dpi'],'byte_count'=>$r['byte_count'],'geometry'=>$g,'content_bounds'=>$c,'rights_receipt_sha256'=>$evidence['rights_receipt_sha256'],'ownership_receipt_sha256'=>$evidence['ownership_receipt_sha256'],'geometry_receipt_sha256'=>$evidence['geometry_receipt_sha256'],'shop_key'=>$scope->shopKey,'integration_id'=>$scope->integrationId,'provider_shop_id'=>$scope->providerShopId,'scope_fingerprint'=>$scope->fingerprint(),'host_evidence_verification_required'=>true];
 }
 private static function rectangle(array $r):void {self::keys($r,['x','y','width','height']);foreach($r as $k=>$v)if(!is_int($v)||$v<(in_array($k,['x','y'],true)?0:1))self::invalid();}
 private static function keys(array $a,array $keys):void {if(array_diff(array_keys($a),$keys)||array_diff($keys,array_keys($a)))self::invalid();}
 private static function invalid():never {throw new \InvalidArgumentException('Exact shop-bound receipts and bounded 300 DPI PNG geometry required.');}
}
