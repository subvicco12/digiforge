<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;
/** Atomic cross-table claim: one authorization hash may own exactly one outcome class. */
final class ExecutionOutcomeClaimRepository{
 public static function claim(string $authorizationHash,string $outcomeType,string $outcomeHash):array|WP_Error{
  $authorizationHash=strtolower(trim($authorizationHash));$outcomeHash=strtolower(trim($outcomeHash));$outcomeType=strtoupper(trim($outcomeType));
  if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash)||!preg_match('/^[a-f0-9]{64}$/',$outcomeHash)||!in_array($outcomeType,['SUCCEEDED','FAILED','UNKNOWN'],true))return new WP_Error('digiforge_outcome_claim_binding','Valid authorization, outcome type, and outcome hash required.',['status'=>400]);
  global $wpdb;$table=Tables::pod_execution_outcomes();$row=['authorization_hash'=>$authorizationHash,'outcome_type'=>$outcomeType,'outcome_hash'=>$outcomeHash,'created_at'=>current_time('mysql',true)];
  if($wpdb->insert($table,$row)!==false){$row['id']=(int)$wpdb->insert_id;return $row;}
  $winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
  if(is_array($winner)&&hash_equals((string)$winner['outcome_hash'],$outcomeHash)&&hash_equals((string)$winner['outcome_type'],$outcomeType))return $winner;
  return new WP_Error('digiforge_outcome_claim_conflict','Authorization is already claimed by a different execution outcome.',['status'=>409]);
 }
}
