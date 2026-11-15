<?php
declare(strict_types=1);
namespace DigiForge\IndependentAcceptance;
use DigiForge\Connector\Printify\NativePortContract;

/** Pure diagnostics. No native calls, database, transport, credential, epoch or authority issuance. */
final class NativeObservationConformance
{
 public const CORE='78e7f973455a3c89319be06f0907caf2103658a7';
 /**
  * @param array<string,mixed> $manifest
  * @param array<string,mixed> $inspection
  * @param array<string,mixed> $binding
  * @param array<string,mixed> $observation
  * @param array<string,mixed> $observerBinding
  * @return array<string,mixed>
  */
 public static function compare(array $manifest,array $inspection,array $binding,array $observation,array $observerBinding,int $now,int $generation):array
 {
  $reasons=[];$scope='UNAVAILABLE';$kind='UNAVAILABLE';$state='UNAVAILABLE';$dataOnly=true;
  try{
   // The core's recursive data guard runs before json_encode/hash. PHP objects
   // can execute JsonSerializable callbacks even if their eventual data is denied.
   foreach([$manifest,$inspection,$binding,$observation,$observerBinding]as $record)if(!NativePortContract::valid('json_object',$record)){$dataOnly=false;throw new \InvalidArgumentException('Data-only records required.');}
   if(!NativePortContract::valid('manifest',$manifest)||($manifest['core_source_sha']??null)!==self::CORE||($manifest['spec_sha256']??null)!==NativePortContract::digest()||($manifest['capabilities']??null)!==['PANEL','GET_READ']||($manifest['external_execution_authorized']??null)!==false)$reasons[]='CORE_MANIFEST';
   $bindings=NativePortContract::valid('binding',$binding)&&NativePortContract::valid('binding',$observerBinding);
   if(!$bindings)$reasons[]='BINDING_SCHEMA';
   else{
    $scope=NativePortContract::hash($binding)===NativePortContract::hash($observerBinding)?'MATCH':'MISMATCH';
    if($scope!=='MATCH')$reasons[]='SCOPE_MISMATCH';
    if($binding['shop_id']!==2||$binding['shop_key']!=='personalized_pod'||$binding['environment']!=='test'||$binding['credential_reference']!==$binding['integration_id'])$reasons[]='INITIAL_TEST_SCOPE';
   }
   $nativeKeys=['schema','version','kind','scope_fingerprint','actor_id','access_receipt_hash','observation','read_only','mutation_authorized','independent_checkpoint_verified','recovery_epoch','checkpoint_epoch','network_request_permitted','external_execution_authorized','retry_permitted','release_hold','evidence_hash'];
   if(!self::exact($inspection,$nativeKeys)||strlen(json_encode($inspection,JSON_THROW_ON_ERROR))>16384||($inspection['schema']??null)!=='DF_NATIVE_READ_ONLY_INSPECTION_1'||($inspection['version']??null)!=='1.0.0')$reasons[]='CORE_INSPECTION_FORMAT';
   else{
    $unsigned=$inspection;unset($unsigned['evidence_hash']);
    if(!self::digest($inspection['evidence_hash'])||NativePortContract::hash($unsigned)!==$inspection['evidence_hash'])$reasons[]='CORE_INSPECTION_INTEGRITY';
    if(($inspection['scope_fingerprint']??null)!==($binding['scope_fingerprint']??null)||($inspection['actor_id']??null)!==($binding['actor_id']??null)||!self::digest($inspection['access_receipt_hash']))$reasons[]='CORE_INSPECTION_BINDING';
    foreach(['mutation_authorized','independent_checkpoint_verified','network_request_permitted','external_execution_authorized','retry_permitted']as $key)if($inspection[$key]!==false)$reasons[]='CORE_AUTHORITY_CLAIM';
    if($inspection['read_only']!==true||$inspection['release_hold']!==true||$inspection['recovery_epoch']!==null||$inspection['checkpoint_epoch']!==null)$reasons[]='CORE_AUTHORITY_CLAIM';
    $v=$inspection['observation'];
    if($inspection['kind']==='READINESS'&&is_array($v)&&NativePortContract::hash($v)===NativePortContract::hash(['available_local_reads'=>['READINESS','HELD_OPERATION','ORDER_OBSERVATIONS'],'epoch_dependent_operations'=>'DENIED','authority_evidence'=>'NOT_QUALIFIED','observation_scope'=>'LOCAL_CORE_ONLY']))$kind='READINESS';
    elseif($inspection['kind']==='HELD_OPERATION'&&is_array($v)&&self::exact($v,['state','journal_revision','occurred_at','event_hash','fence_present','approval_verified','native_product_version','manual_review_required'])&&in_array($v['state'],['HELD','FENCED','UNKNOWN','CANCELLED'],true)&&$v['journal_revision']===(['HELD'=>1,'FENCED'=>2,'UNKNOWN'=>3,'CANCELLED'=>2][$v['state']])&&is_int($v['occurred_at'])&&$v['occurred_at']>0&&self::digest($v['event_hash'])&&$v['fence_present']===in_array($v['state'],['FENCED','UNKNOWN'],true)&&$v['approval_verified']===false&&$v['native_product_version']===null&&$v['manual_review_required']===true){$kind='HELD_OPERATION';$state=$v['state'];}
    else $reasons[]='CORE_OBSERVATION_FORMAT';
   }
   $observationKeys=['schema','version','recovery_epoch','checkpoint_epoch','recovery_revision','generation','root_revision','witness_head','witness_sequence','witness_observed_at','evidence_expires_at','evidence_currentness','observation_hash','cryptographically_verified','independent_recovery_qualified','mutation_authorized','external_execution_authorized','operational_authority','retry_permitted','release_hold'];
   if(!self::exact($observation,$observationKeys)||strlen(json_encode($observation,JSON_THROW_ON_ERROR))>16384||($observation['schema']??null)!=='DF_NATIVE_CHECKPOINT_OBSERVATION_1'||($observation['version']??null)!=='1.0.0')$reasons[]='OBSERVER_FORMAT';
   else{
    foreach(['recovery_epoch','checkpoint_epoch','witness_head','observation_hash']as $key)if(!self::digest($observation[$key]))$reasons[]='OBSERVER_FORMAT';
    foreach(['recovery_revision','generation','root_revision','witness_sequence','witness_observed_at','evidence_expires_at']as $key)if(!is_int($observation[$key])||$observation[$key]<1||$observation[$key]>9007199254740991)$reasons[]='OBSERVER_FORMAT';
    if($observation['recovery_epoch']!==$observation['checkpoint_epoch']||$observation['witness_sequence']!==$observation['root_revision'])$reasons[]='OBSERVER_VECTOR';
    if($observation['evidence_currentness']!=='WITNESSED_AT_SIGNING_ONLY'||$observation['cryptographically_verified']!==true||$observation['release_hold']!==true)$reasons[]='OBSERVER_FORMAT';
    foreach(['independent_recovery_qualified','mutation_authorized','external_execution_authorized','operational_authority','retry_permitted']as $key)if($observation[$key]!==false)$reasons[]='OBSERVER_AUTHORITY_CLAIM';
    if($now<1||$observation['witness_observed_at']>$now||$observation['evidence_expires_at']<=$now||$observation['evidence_expires_at']>$observation['witness_observed_at']+30)$reasons[]='OBSERVER_EXPIRED';
    if($generation<1||$observation['generation']!==$generation)$reasons[]='GENERATION_REFERENCE_MISMATCH';
   }
  }catch(\Throwable){$reasons[]=$dataOnly?'CONTRACT_UNAVAILABLE':'NON_JSON_RECORD';}
  $reasons=array_values(array_unique($reasons));sort($reasons);
  return ['schema'=>'DF_NATIVE_OBSERVER_CONFORMANCE_1','version'=>'1.0.0','status'=>$reasons===[]?'READ_ONLY_CONFORMANT':'READ_ONLY_INCOMPATIBLE','reasons'=>$reasons,'core_reference_sha'=>self::CORE,'scope_comparison'=>$scope,'native_kind'=>$kind,'native_state'=>$state,'signature_verification'=>'NOT_PERFORMED_BY_COMPARATOR','context_link_verified'=>false,'native_runtime_verified'=>false,'read_only'=>true,'independent_checkpoint_verified'=>false,'recovery_epoch'=>null,'checkpoint_epoch'=>null,'mutation_authorized'=>false,'dispatch_permitted'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false,'retry_permitted'=>false,'automatic_rearm'=>false,'release_hold'=>true];
 }
 /**
  * @param array<mixed> $v
  * @param list<string> $keys
  */
 private static function exact(array $v,array $keys):bool{$actual=array_keys($v);sort($actual);sort($keys);return $actual===$keys;}
 private static function digest(mixed $v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
}
