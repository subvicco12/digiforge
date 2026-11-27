<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** No route or form. Core validates current WordPress session, nonce and exact panel scope. */
final class ProtectedPanel {
 public function __construct(private NativePortAdapter $port){}
 public function snapshot(ShopBinding $s,int $actor,array $context):array|\WP_Error {
  $access=$this->port->panelAccess($s,$actor,$context);if(is_wp_error($access))return $access;$q=(new ReviewCoordinator($this->port))->queue($s,$actor);if(is_wp_error($q))return $q;
  foreach($q['items']as $item)if($item['recovery_epoch']!==$q['checkpoint_epoch'])return new \WP_Error('printify_panel_stale','Current core checkpoint evidence required.');
  $items=array_values(array_filter($q['items'],static fn($i)=>match($context['view']){'publication'=>$i['kind']==='PUBLICATION','orders'=>$i['kind']==='ORDER','fulfillment'=>$i['kind']==='FULFILLMENT','conflicts'=>$i['operation_state']==='CONFLICTING','unknown'=>$i['operation_state']==='UNKNOWN',default=>false}));
  if(is_wp_error($this->port->panelAccess($s,$actor,$context)))return new \WP_Error('printify_panel_revoked','Current panel authority unavailable.');return ['schema'=>'DF_PROTECTED_PANEL_1','view'=>$context['view'],'scope_fingerprint'=>$s->fingerprint(),'items'=>$items,'exceptions'=>array_map(static fn($i)=>ExceptionReconciler::classify($i),$items),'core_access_receipt'=>$access['receipt_hash'],'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 /** Selection is read-only: only the current scoped queue can supply an item. */
 public function inspect(ShopBinding $s,int $actor,array $context,string $subject,int $revision):array|\WP_Error {
  if(!preg_match('/^[a-f0-9]{64}$/D',$subject)||$revision<1)return new \WP_Error('printify_panel_selection','Current scoped operation required.');
  $snapshot=$this->snapshot($s,$actor,$context);if(is_wp_error($snapshot))return $snapshot;
  $matches=array_values(array_filter($snapshot['items'],static fn($i)=>$i['subject_hash']===$subject));
  if(count($matches)!==1||$matches[0]['review_revision']!==$revision)return new \WP_Error('printify_panel_stale','Operation absent, ambiguous or revision stale.');
  return ['schema'=>'DF_PANEL_INSPECTION_1','item'=>$matches[0],'exception'=>ExceptionReconciler::classify($matches[0]),'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public function render(ShopBinding $s,int $a,array $c):string {
  $r=$this->snapshot($s,$a,$c);if(is_wp_error($r))return '<p>Protected operation evidence unavailable. No execution authorized.</p>';
  $escape=static fn(string $v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $h='<section><h2>Protected Printify review</h2><p>Read only. No provider execution, retries or approval changes.</p><table><thead><tr><th>Kind</th><th>Operation</th><th>Review</th><th>Review revision</th><th>Product / order version</th><th>Blockers</th><th>Required evidence</th></tr></thead><tbody>';
  foreach($r['items']as $index=>$i){$e=$r['exceptions'][$index];$h.='<tr>';foreach([$i['kind'],$i['operation_state'],$i['review_state'],(string)$i['review_revision'],$i['product_version'].' / '.$i['order_version'],implode(', ',$i['blockers'])?:'No recorded blockers',$e['classification'].' '.$e['next_evidence']]as $v)$h.='<td>'.$escape($v).'</td>';$h.='</tr>';}
  if($r['items']===[])$h.='<tr><td colspan="7">No matching scoped evidence. Absence does not establish readiness.</td></tr>';
  return $h.'</tbody></table></section>';
 }
 public function export(ShopBinding $s,int $a,array $c):string|\WP_Error{$r=$this->snapshot($s,$a,$c);if(is_wp_error($r))return $r;$r['export_hash']=MutationIntent::hash($r);return json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);}
}
