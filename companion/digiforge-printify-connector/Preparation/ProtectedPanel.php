<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** No route or form. Core validates current WordPress session, nonce and exact panel scope. */
final class ProtectedPanel {
 public function __construct(private NativePortAdapter $port){}
 public function snapshot(ShopBinding $s,int $actor,array $context):array|\WP_Error {
  $access=$this->port->panelAccess($s,$actor,$context);if(is_wp_error($access))return $access;$q=(new ReviewCoordinator($this->port))->queue($s,$actor);if(is_wp_error($q))return $q;
  $items=array_values(array_filter($q['items'],static fn($i)=>match($context['view']){'publication'=>$i['kind']==='PUBLICATION','orders'=>$i['kind']==='ORDER','fulfillment'=>$i['kind']==='FULFILLMENT','conflicts'=>$i['operation_state']==='CONFLICTING','unknown'=>$i['operation_state']==='UNKNOWN',default=>false}));
  if(is_wp_error($this->port->panelAccess($s,$actor,$context)))return new \WP_Error('printify_panel_revoked','Current panel authority unavailable.');return ['schema'=>'DF_PROTECTED_PANEL_1','view'=>$context['view'],'scope_fingerprint'=>$s->fingerprint(),'items'=>$items,'exceptions'=>array_map(static fn($i)=>ExceptionReconciler::classify($i),$items),'core_access_receipt'=>$access['receipt_hash'],'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public function render(ShopBinding $s,int $a,array $c):string{$r=$this->snapshot($s,$a,$c);if(is_wp_error($r))return '<p>Protected operation evidence unavailable. No execution authorized.</p>';$h='<section><h2>Protected Printify review</h2><p>Read only; core review cannot grant provider execution.</p><ul>';foreach($r['items']as $i)$h.='<li>'.htmlspecialchars($i['kind'].' '.$i['operation_state'].' '.$i['review_state'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>';foreach($r['exceptions']as $e)$h.='<li>'.htmlspecialchars($e['classification'].' '.$e['next_evidence'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>';return $h.'</ul></section>';}
 public function export(ShopBinding $s,int $a,array $c):string|\WP_Error{$r=$this->snapshot($s,$a,$c);if(is_wp_error($r))return $r;$r['export_hash']=MutationIntent::hash($r);return json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);}
}
