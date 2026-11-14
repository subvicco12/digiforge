<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\{ShopBinding,NativeBusinessMutationEvidence};
/** Protected SELECT-only source inspection component. No route, registration, approval or dispatch action. */
final class HeldPreparationPanel {
 public function __construct(private NativePortAdapter $port){}
 public function snapshot(ShopBinding $s,int $actor,array $context,int $mapping,int $spec,string $plan):array|\WP_Error {
  try{
   if(($context['view']??null)!=='unknown'||$mapping<1||$spec<1||!preg_match('/^[a-f0-9]{64}$/D',$plan))return self::error();
   $access=$this->port->panelAccess($s,$actor,$context);if(is_wp_error($access)||!class_exists(NativeBusinessMutationEvidence::class)||NativeBusinessMutationEvidence::version()!=='1.0.0')return self::error();
   $source=NativeBusinessMutationEvidence::resolve($s,$actor,$mapping,$spec,$plan);if(is_wp_error($source))return self::error();$view=HeldPreparationReadModel::observe($s,$actor,$source);if(is_wp_error($view))return self::error();
   // The core revalidates the current source; a panel nonce never substitutes for business authority.
   $current=NativeBusinessMutationEvidence::resolve($s,$actor,$mapping,$spec,$plan,$source['evidence_hash']);if(is_wp_error($current)||is_wp_error($this->port->panelAccess($s,$actor,$context)))return self::error();
   return ['schema'=>'DF_PROTECTED_HELD_SOURCE_PANEL_1','view'=>'unknown','observation'=>$view,'core_access_receipt'=>$access['receipt_hash'],'read_only'=>true,'retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false];
  }catch(\Throwable){return self::error();}
 }
 public function render(ShopBinding $s,int $actor,array $context,int $mapping,int $spec,string $plan):string {
  $r=$this->snapshot($s,$actor,$context,$mapping,$spec,$plan);if(is_wp_error($r))return '<p>Protected source evidence unavailable. No mutation authorized.</p>';
  $o=$r['observation'];$escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  return '<section><h2>Held source preparation</h2><p>Read only. No mutation authorized.</p><dl><dt>Operation</dt><dd>'.$escape($o['operation']).'</dd><dt>Business version reference</dt><dd>'.$escape((string)$o['business_product_version_id']).'</dd><dt>Native mutation version</dt><dd>UNKNOWN</dd><dt>Payload equivalence</dt><dd>UNVERIFIED</dd><dt>Mutation approval</dt><dd>NOT GRANTED</dd></dl><p>Independent checkpoint, custody and exact provider payload approval remain required.</p></section>';
 }
 private static function error():\WP_Error{return new \WP_Error('printify_held_panel_denied','Current protected source evidence required; no mutation authorized.',['read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
