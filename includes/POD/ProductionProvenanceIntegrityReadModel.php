<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;

/** Read-only drill-down for production provenance anomalies. Never authorizes execution or retry. */
final class ProductionProvenanceIntegrityReadModel{
 public function recent(int $limit=50):array{
  $limit=max(1,min(200,$limit));global $wpdb;$items=[];
  $bindings=$wpdb->get_results($wpdb->prepare("SELECT b.authorization_hash,b.package_id,b.package_hash,p.package_hash AS actual_package_hash FROM ".Tables::pod_authorization_bindings()." b LEFT JOIN ".Tables::pod_authorization_packages()." p ON p.id=b.package_id WHERE p.id IS NULL OR p.package_hash<>b.package_hash ORDER BY b.id DESC LIMIT %d",$limit),ARRAY_A);
  foreach((array)$bindings as $row)$items[]=['type'=>'BINDING_PACKAGE_MISMATCH']+$row;
  if(count($items)<$limit){$remaining=$limit-count($items);$closures=$wpdb->get_results($wpdb->prepare("SELECT c.authorization_hash,c.package_id,c.package_hash,p.package_hash AS actual_package_hash FROM ".Tables::pod_lifecycle_closures()." c LEFT JOIN ".Tables::pod_authorization_packages()." p ON p.id=c.package_id WHERE p.id IS NULL OR p.package_hash<>c.package_hash ORDER BY c.id DESC LIMIT %d",$remaining),ARRAY_A);foreach((array)$closures as $row)$items[]=['type'=>'CLOSURE_PACKAGE_MISMATCH']+$row;}
  if(count($items)<$limit){$remaining=$limit-count($items);$legacy=$wpdb->get_results($wpdb->prepare("SELECT n.authorization_hash,0 AS package_id,'' AS package_hash,NULL AS actual_package_hash FROM ".Tables::pod_execution_nonces()." n LEFT JOIN ".Tables::pod_authorization_bindings()." b ON b.authorization_hash=n.authorization_hash LEFT JOIN ".Tables::pod_lifecycle_closures()." c ON c.authorization_hash=n.authorization_hash WHERE b.id IS NULL AND c.id IS NULL ORDER BY n.id DESC LIMIT %d",$remaining),ARRAY_A);foreach((array)$legacy as $row)$items[]=['type'=>'LEGACY_UNBOUND']+$row;}
  foreach($items as &$item){$auth=(string)$item['authorization_hash'];$item['outcome']=$wpdb->get_row($wpdb->prepare('SELECT outcome_type,outcome_hash,created_at FROM '.Tables::pod_execution_outcomes().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);$item['closure']=$wpdb->get_row($wpdb->prepare('SELECT outcome_state,closure_hash,external_execution_state,created_at FROM '.Tables::pod_lifecycle_closures().' WHERE authorization_hash=%s LIMIT 1',$auth),ARRAY_A);$item['correlation_hash']=hash('sha256',implode('|',[$item['type'],$auth,(string)$item['package_id'],(string)$item['package_hash'],(string)($item['actual_package_hash']??''),(string)($item['outcome']['outcome_hash']??''),(string)($item['closure']['closure_hash']??'')]));}$item=null;
  return ['items'=>$items,'count'=>count($items),'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
}
