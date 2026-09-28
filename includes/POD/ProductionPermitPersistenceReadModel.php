<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;

/** Read-only reconciliation of an ambiguous permit persistence boundary. Never permits retry or execution. */
final class ProductionPermitPersistenceReadModel{
 public function project(string $nonceHash,string $authorizationHash,int $packageId,string $packageHash):array{
  global $wpdb;$nonce=$wpdb->get_row($wpdb->prepare('SELECT authorization_hash,consumed_by,consumed_at FROM '.Tables::pod_execution_nonces().' WHERE nonce_hash=%s LIMIT 1',$nonceHash),ARRAY_A);$binding=$wpdb->get_row($wpdb->prepare('SELECT package_id,package_hash,authorization_hash,nonce_hash,bound_by,bound_at FROM '.Tables::pod_authorization_bindings().' WHERE authorization_hash=%s LIMIT 1',$authorizationHash),ARRAY_A);
  $persisted=is_array($nonce)&&hash_equals((string)$nonce['authorization_hash'],$authorizationHash)&&is_array($binding)&&(int)$binding['package_id']===$packageId&&hash_equals((string)$binding['package_hash'],$packageHash)&&hash_equals((string)$binding['nonce_hash'],$nonceHash);
  return ['persistence_state'=>$persisted?'PERSISTED_OBSERVED':'UNKNOWN','nonce_consumed'=>$persisted,'nonce_evidence'=>$nonce,'binding_evidence'=>$binding,'retry_permitted'=>false,'external_execution_authorized'=>false,'read_only'=>true];
 }
}
