<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Pure request construction and metadata observation; never transport or provider ownership proof. */
final class UploadContract
{
 public static function prepare(ShopBinding $scope,array $asset,string $bytes):array
 {
  $e=[];foreach(['rights_receipt_sha256','ownership_receipt_sha256','geometry_receipt_sha256','shop_key','integration_id','provider_shop_id','geometry','content_bounds'] as $key){if(!array_key_exists($key,$asset))self::invalid();$e[$key]=$asset[$key];}
  $e['asset_sha256']=$asset['sha256']??'';$checked=ArtworkContract::inspect($bytes,$e,$scope);
  if(!self::same($checked,$asset))self::invalid();
  $identity=hash('sha256',$scope->fingerprint().':'.$checked['sha256']);
  $body=['file_name'=>$checked['sha256'].'.png','contents'=>base64_encode($bytes)];
  return ['method'=>'POST','path'=>'/v1/uploads/images.json','body'=>$body,'asset'=>$checked,'scope_fingerprint'=>$scope->fingerprint(),'duplicate_identity'=>$identity,'request_fingerprint'=>hash('sha256',json_encode([$scope->fingerprint(),'POST','/v1/uploads/images.json',$body,$checked],JSON_THROW_ON_ERROR)),'network_request_permitted'=>false];
 }
 /** Creates a NEW inert request. Never alters or renews an existing approved intent. */
 public static function prepareFromCoreDescriptor(ShopBinding $scope,array $asset,string $bytes,array $descriptor,int $actor):array
 {
  if($scope->shopId!==2||$scope->shopKey!=='personalized_pod')self::invalid();
  $r=self::prepare($scope,$asset,$bytes);
  $d=CoreUploadWireContract::validate($descriptor,$scope->fingerprint(),$actor,$r['asset']['sha256'],$r['body']['contents']);
  $r['body']['file_name']=$d['file_name'];$r['core_wire_descriptor']=$d;$r['source_authenticated']=false;$r['current_core_verification_required']=true;
  $r['request_fingerprint']=hash('sha256',json_encode([$r['scope_fingerprint'],'POST','/v1/uploads/images.json',$r['body'],$r['asset'],$d],JSON_THROW_ON_ERROR));return $r;
 }
 public static function reconcile(array $request,array $response,int $status=200):array
 {
  // Reconstruct every request field so tampered bodies, dimensions and fingerprints fail closed.
  $core=array_key_exists('core_wire_descriptor',$request);$keys=['method','path','body','asset','scope_fingerprint','duplicate_identity','request_fingerprint','network_request_permitted'];if($core)$keys=array_merge($keys,['core_wire_descriptor','source_authenticated','current_core_verification_required']);
  if(!self::keys($request,$keys)||$request['method']!=='POST'||$request['path']!=='/v1/uploads/images.json'||$request['network_request_permitted']!==false||!is_array($request['body'])||!self::keys($request['body'],['file_name','contents'])||!is_array($request['asset']))self::invalid();
  $a=$request['asset'];$body=$request['body'];
  if(!is_string($body['contents'])||!is_string($a['sha256']??null)||!is_string($request['scope_fingerprint'])||($a['scope_fingerprint']??null)!==$request['scope_fingerprint'])self::invalid();
  $d=null;if($core){if(!is_array($request['core_wire_descriptor'])||$request['source_authenticated']!==false||$request['current_core_verification_required']!==true||!is_int($request['core_wire_descriptor']['actor_id']??null))self::invalid();$d=CoreUploadWireContract::validate($request['core_wire_descriptor'],$request['scope_fingerprint'],$request['core_wire_descriptor']['actor_id'],$a['sha256'],$body['contents']);}
  if($body['file_name']!==($d['file_name']??$a['sha256'].'.png'))self::invalid();
  $bytes=base64_decode($body['contents'],true);if(!is_string($bytes)||base64_encode($bytes)!==$body['contents']||hash('sha256',$bytes)!==$a['sha256'])self::invalid();
  $r=\DigiForge\Research\DesignFactoryContract::raster($bytes,['geometry'=>['width_px'=>$a['width_px']??0,'height_px'=>$a['height_px']??0]]);
  foreach(['sha256','mime_type','width_px','height_px','dpi','byte_count'] as $key)if(($a[$key]??null)!==$r[$key])self::invalid();
  $material=[$request['scope_fingerprint'],'POST','/v1/uploads/images.json',$body,$a];if($d!==null)$material[]=$d;
  if($request['duplicate_identity']!==hash('sha256',$request['scope_fingerprint'].':'.$a['sha256'])||$request['request_fingerprint']!==hash('sha256',json_encode($material,JSON_THROW_ON_ERROR)))self::invalid();
  $flags=$core?['source_authenticated'=>false,'current_core_verification_required'=>true]:[];
  if($status!==200)return ['state'=>'UNKNOWN','retry_permitted'=>false,'status'=>$status,'request_fingerprint'=>$request['request_fingerprint']]+$flags;
  if(!is_string($response['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$response['id'])||($response['file_name']??null)!==$body['file_name']||($response['width']??null)!==$a['width_px']||($response['height']??null)!==$a['height_px'])self::invalid();
  return ['state'=>'METADATA_OBSERVED_CONTENT_UNVERIFIED','id'=>$response['id'],'file_name'=>$response['file_name'],'width'=>$response['width'],'height'=>$response['height'],'scope_fingerprint'=>$request['scope_fingerprint'],'request_fingerprint'=>$request['request_fingerprint'],'duplicate_identity'=>$request['duplicate_identity'],'provider_content_verified'=>false,'ownership_certified'=>false]+$flags;
 }
 private static function same(array $a,array $b):bool {if(!self::keys($b,array_keys($a)))return false;foreach($a as $k=>$v)if(is_array($v)?(!is_array($b[$k])||!self::same($v,$b[$k])):$v!==$b[$k])return false;return true;}
 private static function keys(array $a,array $keys):bool {return !array_diff(array_keys($a),$keys)&&!array_diff($keys,array_keys($a));}
 private static function invalid():never {throw new \InvalidArgumentException('Exact offline upload request and matching provider metadata required.');}
}
