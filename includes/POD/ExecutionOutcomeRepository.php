<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Read-only lookup for the immutable terminal outcome bound to one authorization. */
final class ExecutionOutcomeRepository
{
 /** @return array<string,mixed>|WP_Error */
 public static function findByAuthorizationHash(string $authorizationHash):array|WP_Error
 {
  if(!preg_match('/^[a-f0-9]{64}$/',$authorizationHash))
   return new WP_Error('digiforge_outcome_authorization','Valid authorization hash required.',['status'=>400]);
  global $wpdb;
  $success=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_execution_receipts().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
  $failure=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_execution_failures().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
  if(is_array($success)&&is_array($failure))
   return new WP_Error('digiforge_outcome_invariant','Authorization has conflicting terminal outcomes.',['status'=>409]);
  if(is_array($success))return ['state'=>'EXECUTION_SUCCEEDED','receipt'=>$success];
  if(is_array($failure))return ['state'=>'EXECUTION_FAILED','failure'=>$failure,'retry_permitted'=>false];
  return ['state'=>'EXECUTION_OUTCOME_NOT_FOUND'];
 }
}
