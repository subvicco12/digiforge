<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;
/** Immutable local human acceptance of exact Master 500 v2 migration evidence. Never grants promotion or external authority. */
final class MasterCatalogV2AcceptanceRepository {
 public function record(int $versionId,string $decision,int $reviewerId):array|WP_Error {
  global $wpdb;$decision=strtoupper(trim($decision));
  if($versionId<1||$reviewerId<1||!in_array($decision,['ACCEPT','REJECT'],true))return new WP_Error('invalid_catalog_acceptance','Concrete candidate, reviewer and ACCEPT/REJECT decision are required.',[],400);
  $wpdb->last_error='';$v=$wpdb->get_row($wpdb->prepare('SELECT id,parent_version_id,source_sha256,source_state,row_count,fingerprint,production_authority FROM '.Tables::catalog_versions().' WHERE id=%d AND catalog_key=%s LIMIT 1',$versionId,MasterCatalogV2Reference::CATALOG_KEY),ARRAY_A);
  if(!is_array($v)||!empty($wpdb->last_error))return new WP_Error('catalog_acceptance_evidence_unavailable','Authoritative candidate evidence is unavailable.',[],503);
  if((string)$v['source_state']!=='MIGRATION_CANDIDATE'||(int)$v['row_count']!==MasterCatalogV2Reference::ROW_COUNT||(int)$v['production_authority']!==0||!hash_equals(MasterCatalogV2Reference::SOURCE_SHA256,(string)$v['source_sha256'])||preg_match('/^[a-f0-9]{64}$/',(string)$v['fingerprint'])!==1)return new WP_Error('catalog_acceptance_candidate_invalid','Candidate evidence does not match the governed non-authorizing Master 500 v2 identity.',[],409);
  $payload=['catalog_version_id'=>$versionId,'parent_version_id'=>(int)$v['parent_version_id'],'source_sha256'=>(string)$v['source_sha256'],'fingerprint'=>(string)$v['fingerprint'],'decision'=>$decision,'reviewer_id'=>$reviewerId];
  $hash=hash('sha256',wp_json_encode($payload));
  $wpdb->last_error='';$prior=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_acceptance_evidence().' WHERE catalog_version_id=%d LIMIT 1',$versionId),ARRAY_A);
  if(!empty($wpdb->last_error))return new WP_Error('catalog_acceptance_evidence_unavailable','Existing acceptance evidence could not be verified.',[],503);
  if(is_array($prior)){
   if(!hash_equals($hash,(string)($prior['acknowledgement_hash']??'')))return new WP_Error('catalog_acceptance_conflict','This exact candidate already has different immutable human acceptance evidence.',[],409);
   return ['query_state'=>'AVAILABLE','decision'=>$decision,'acknowledgement_hash'=>$hash,'catalog_version_id'=>$versionId,'parent_version_id'=>$payload['parent_version_id'],'production_authority'=>false,'promotion_authorized'=>false,'external_execution_authorized'=>false,'replayed'=>true];
  }
  $wpdb->last_error='';$ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.Tables::catalog_acceptance_evidence().' (catalog_version_id,parent_version_id,source_sha256,fingerprint,decision,reviewer_id,acknowledgement_hash,reviewed_at) VALUES (%d,%d,%s,%s,%s,%d,%s,%s)',$versionId,$payload['parent_version_id'],$payload['source_sha256'],$payload['fingerprint'],$decision,$reviewerId,$hash,current_time('mysql',true)));
  if($ok===false||!empty($wpdb->last_error))return new WP_Error('catalog_acceptance_persistence_failed','Acceptance evidence persistence is uncertain.',[],503);
  $wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::catalog_acceptance_evidence().' WHERE acknowledgement_hash=%s LIMIT 1',$hash),ARRAY_A);
  if(!is_array($row)||!empty($wpdb->last_error)||!hash_equals($hash,(string)($row['acknowledgement_hash']??'')))return new WP_Error('catalog_acceptance_readback_failed','Acceptance evidence read-back could not be verified.',[],503);
  foreach(['catalog_version_id','parent_version_id','reviewer_id'] as $k)if((int)$row[$k]!== (int)$payload[$k])return new WP_Error('catalog_acceptance_conflict','Stored acceptance evidence conflicts with this exact decision.',[],409);
  foreach(['source_sha256','fingerprint','decision'] as $k)if(!hash_equals((string)$payload[$k],(string)$row[$k]))return new WP_Error('catalog_acceptance_conflict','Stored acceptance evidence conflicts with this exact decision.',[],409);
  return ['query_state'=>'AVAILABLE','decision'=>$decision,'acknowledgement_hash'=>$hash,'catalog_version_id'=>$versionId,'parent_version_id'=>$payload['parent_version_id'],'production_authority'=>false,'promotion_authorized'=>false,'external_execution_authorized'=>false,'replayed'=>$ok===0];
 }
}