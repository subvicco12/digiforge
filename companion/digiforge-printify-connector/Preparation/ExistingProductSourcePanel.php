<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\{ShopBinding,NativeExistingProductMutationEvidence as Evidence};
/** Protected read-only component; no route, approval, retry, mutation intent or sender. */
final class ExistingProductSourcePanel {
 public function __construct(private NativePortAdapter $port){}
 public function snapshot(ShopBinding $s,int $actor,array $context,int $mapping,int $spec,string $plan,string $readReceipt):array|\WP_Error {
  try{
   if(($context['view']??null)!=='unknown'||$mapping<1||$spec<1||!preg_match('/^[a-f0-9]{64}$/D',$plan)||!preg_match('/^[a-f0-9]{64}$/D',$readReceipt))return self::error();
   $access=$this->port->panelAccess($s,$actor,$context);if(is_wp_error($access)||!defined('DIGIFORGE_PATH')||!class_exists(Evidence::class)||Evidence::version()!=='1.0.0')return self::error();
   $expected=realpath(DIGIFORGE_PATH.'includes/Connector/Printify/NativeExistingProductMutationEvidence.php');$actual=(new \ReflectionClass(Evidence::class))->getFileName();if($expected===false||!is_string($actual)||realpath($actual)!==$expected)return self::error();
   $r=Evidence::resolve($s,$actor,$mapping,$spec,$plan,$readReceipt);if(is_wp_error($r))return self::error();$view=ExistingProductSourceReadModel::observe($s,$actor,$r);if(is_wp_error($view))return self::error();
   $current=Evidence::resolve($s,$actor,$mapping,$spec,$plan,$readReceipt,$r['evidence_hash']);if(is_wp_error($current)||is_wp_error($this->port->panelAccess($s,$actor,$context)))return self::error();
   return ['schema'=>'DF_PROTECTED_EXISTING_PRODUCT_SOURCE_PANEL_1','view'=>'unknown','observation'=>$view,'core_access_receipt'=>$access['receipt_hash'],'read_only'=>true,'retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return self::error();}
 }
 public function render(ShopBinding $s,int $actor,array $context,int $mapping,int $spec,string $plan,string $readReceipt):string {
  $r=$this->snapshot($s,$actor,$context,$mapping,$spec,$plan,$readReceipt);if(is_wp_error($r))return '<p>Protected existing-product source unavailable. No mutation authorized.</p>';
  $v=$r['observation'];$escape=static fn(string $x):string=>htmlspecialchars($x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  return '<section><h2>Existing product price source</h2><p>Read only. No mutation authorized.</p><dl><dt>Provider product</dt><dd>'.$escape($v['provider_product_id']).'</dd><dt>Payload scope</dt><dd>PRICE ONLY</dd><dt>Selected variant</dt><dd>'.$escape((string)$v['variant_id']).'</dd><dt>Proposed price in minor units</dt><dd>'.$escape((string)$v['price_minor']).'</dd><dt>Currency</dt><dd>UNKNOWN</dd><dt>Provider observation</dt><dd>'.$escape($v['observed_at']).'</dd><dt>Adopted observation</dt><dd>'.$escape($v['adopted_at']).'</dd><dt>Business version reference</dt><dd>'.$escape((string)$v['business_product_version_id']).'</dd><dt>Native mutation version</dt><dd>UNKNOWN</dd><dt>Provider currentness</dt><dd>UNVERIFIED</dd><dt>Full product-draft equivalence</dt><dd>UNVERIFIED</dd></dl><p>Fresh core revalidation, exact mutation approval and independent recovery/custody remain required.</p></section>';
 }
 private static function error():\WP_Error{return new \WP_Error('printify_existing_product_panel_denied','Current protected price-only source required; no mutation authorized.',['read_only'=>true,'manual_review_required'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
