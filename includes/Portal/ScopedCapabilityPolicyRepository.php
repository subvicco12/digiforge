<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Database\Tables;
use WP_Error;
/** Append-only versioned shop/workflow policy evidence. Saving policy is not execution authorization. */
final class ScopedCapabilityPolicyRepository {
 public function append(string $shop,string $workflow,string $capability,bool $enabled):array|WP_Error {
  global $wpdb;$shop=sanitize_key($shop);$workflow=sanitize_key($workflow);$capability=sanitize_key($capability);if($shop===''||$workflow===''||$capability==='')return new WP_Error('invalid_scoped_policy','Shop, workflow and capability are required.');
  $prev=$wpdb->get_row($wpdb->prepare('SELECT version,policy_hash FROM '.Tables::scoped_capability_policies().' WHERE shop_key=%s AND workflow_key=%s AND capability=%s ORDER BY version DESC LIMIT 1',$shop,$workflow,$capability),ARRAY_A);$version=is_array($prev)?((int)$prev['version']+1):1;$previous=is_array($prev)?(string)$prev['policy_hash']:'';
  $canonical=wp_json_encode(['shop_key'=>$shop,'workflow_key'=>$workflow,'capability'=>$capability,'enabled'=>$enabled,'version'=>$version,'previous_policy_hash'=>$previous]);$hash=hash('sha256',(string)$canonical);$now=current_time('mysql',true);
  $ok=$wpdb->insert(Tables::scoped_capability_policies(),['shop_key'=>$shop,'workflow_key'=>$workflow,'capability'=>$capability,'enabled'=>$enabled?1:0,'version'=>$version,'policy_hash'=>$hash,'previous_policy_hash'=>$previous,'created_by'=>get_current_user_id(),'created_at'=>$now]);if($ok!==1)return new WP_Error('scoped_policy_persistence_failed','Scoped policy evidence could not be persisted.');
  return ['id'=>(int)$wpdb->insert_id,'shop_key'=>$shop,'workflow_key'=>$workflow,'capability'=>$capability,'enabled'=>$enabled,'version'=>$version,'policy_hash'=>$hash,'previous_policy_hash'=>$previous,'read_only_evidence'=>true,'external_execution_authorized'=>false];
 }
 public function latest(string $shop,string $workflow,string $capability):?array {global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT id,shop_key,workflow_key,capability,enabled,version,policy_hash,previous_policy_hash,created_by,created_at FROM '.Tables::scoped_capability_policies().' WHERE shop_key=%s AND workflow_key=%s AND capability=%s ORDER BY version DESC LIMIT 1',sanitize_key($shop),sanitize_key($workflow),sanitize_key($capability)),ARRAY_A);if(!is_array($r))return null;$r['read_only_evidence']=true;$r['external_execution_authorized']=false;return $r;}
}