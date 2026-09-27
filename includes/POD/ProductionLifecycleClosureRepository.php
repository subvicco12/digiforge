<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;use WP_Error;
final class ProductionLifecycleClosureRepository{
 public static function save(array $record):array|WP_Error{
  if(($record['state']??'')!=='PRODUCTION_LIFECYCLE_CLOSED'||!is_array($record['closure']??null))return new WP_Error('production_closure_state','Valid lifecycle closure evidence required.',['status'=>400]);
  $c=$record['closure'];$hash=strtolower((string)($record['closure_hash']??''));$auth=strtolower((string)($c['authorization_hash']??''));$package=(int)($c['package_id']??0);
  if($package<1||!preg_match('/^[a-f0-9]{64}$/',$hash)||!preg_match('/^[a-f0-9]{64}$/',$auth)||($c['retry_permitted']??null)!==false)return new WP_Error('production_closure_binding','Closure binding is invalid.',['status'=>409]);
  global $wpdb;$table=Tables::pod_lifecycle_closures();$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE package_id=%d OR authorization_hash=%s LIMIT 1',$package,$auth),ARRAY_A);
  if(is_array($existing))return hash_equals((string)$existing['closure_hash'],$hash)?$existing:new WP_Error('production_closure_conflict','Package or authorization already has different closure evidence.',['status'=>409]);
  $row=['package_id'=>$package,'package_hash'=>(string)$c['package_hash'],'authorization_hash'=>$auth,'outcome_state'=>(string)$c['outcome_state'],'closure_hash'=>$hash,'closed_by'=>(int)$c['closed_by'],'external_execution_performed'=>!empty($c['external_execution_performed'])?1:0,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)===false)return new WP_Error('production_closure_store','Lifecycle closure could not be persisted.',['status'=>409]);$row['id']=(int)$wpdb->insert_id;return $row;
 }
}
