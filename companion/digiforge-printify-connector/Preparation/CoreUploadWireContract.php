<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;

/** Minimized core descriptor validation, never source authentication or execution authority. */
final class CoreUploadWireContract
{
 public static function validate(array $d,string $scopeFingerprint,int $actor,string $assetSha,string $contents):array
 {
  $keys=['schema','version','scope_fingerprint','actor_id','source_evidence_hash','plan_hash','package_hash','asset_sha256','file_name','method','endpoint','payload_fingerprint','descriptor_hash'];
  if(count($d)!==count($keys)||array_diff(array_keys($d),$keys)||array_diff($keys,array_keys($d)))self::invalid();
  if($actor<1||$d['schema']!=='DF_NATIVE_IMAGE_UPLOAD_WIRE_1'||$d['version']!=='1.0.0'||$d['actor_id']!==$actor||$d['method']!=='POST'||$d['endpoint']!=='/v1/uploads/images.json')self::invalid();
  foreach(['scope_fingerprint','source_evidence_hash','plan_hash','package_hash','asset_sha256','payload_fingerprint','descriptor_hash']as $key)if(!is_string($d[$key])||preg_match('/^[a-f0-9]{64}$/D',$d[$key])!==1)self::invalid();
  if($d['scope_fingerprint']!==$scopeFingerprint||$d['asset_sha256']!==$assetSha||$d['file_name']!=='shop2-'.substr($d['package_hash'],0,16).'.png')self::invalid();
  // Existing raster contract permits at most 2MiB; reject oversized base64 before decoding.
  if(strlen($contents)>2796204)self::invalid();$bytes=base64_decode($contents,true);
  if(!is_string($bytes)||strlen($bytes)>2097152||base64_encode($bytes)!==$contents||hash('sha256',$bytes)!==$assetSha)self::invalid();
  $wire=['method'=>'POST','endpoint'=>'/v1/uploads/images.json','body'=>['file_name'=>$d['file_name'],'contents'=>$contents]];
  if(!hash_equals($d['payload_fingerprint'],MutationIntent::hash($wire)))self::invalid();
  $hash=$d['descriptor_hash'];unset($d['descriptor_hash']);if(!hash_equals($hash,MutationIntent::hash($d)))self::invalid();$d['descriptor_hash']=$hash;
  $r=[];foreach($keys as $key)$r[$key]=$d[$key];return $r;
 }
 private static function invalid():never{throw new \InvalidArgumentException('Exact minimized core upload descriptor and matching local artwork required.');}
}
