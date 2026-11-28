<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;

/** Pure account-wide upload contracts. Ownership is supplied only by the local resolver. */
final class PrintifyUploadContract
{
 public static function prepare(string $operation,string $shop,array $input=[]):array {
  if(!preg_match('/^[1-9][0-9]{0,18}$/D',$shop))self::bad();
  if($operation==='UPLOADS_READ'){
   if(array_diff(array_keys($input),['page','limit']))self::bad();
   $page=$input['page']??1;$limit=$input['limit']??10;
   if(!is_int($page)||$page<1||$page>2147483647||!is_int($limit)||$limit<1||$limit>10)self::bad();
   $material=['operation'=>$operation,'provider_shop_id'=>$shop,'method'=>'GET','endpoint'=>'/v1/uploads.json?page='.$page.'&limit='.$limit,'body'=>[],'required_scope'=>'uploads.read','input'=>['page'=>$page,'limit'=>$limit]];
  }elseif($operation==='IMAGE_ARCHIVE'){
   if(array_keys($input)!==['image_id'])self::bad();self::id($input['image_id']);
   $material=['operation'=>$operation,'provider_shop_id'=>$shop,'method'=>'POST','endpoint'=>'/v1/uploads/'.$input['image_id'].'/archive.json','body'=>[],'required_scope'=>'uploads.write','input'=>$input];
  }else self::bad();
  return $material+['fingerprint'=>hash('sha256',Validator::canonicalJson($material)),'external_execution_authorized'=>false];
 }
 public static function project(array $request,array $data,int $status=200):array {
  $expected=self::prepare($request['operation']??'', $request['provider_shop_id']??'', $request['input']??[]);
  foreach($expected as $k=>$value)if(Validator::canonicalJson(['value'=>$request[$k]??null])!==Validator::canonicalJson(['value'=>$value]))self::bad();
  if($status!==200)self::bad();
  $owned=$request['owned_images']??null;
  if(!is_array($owned)||!array_is_list($owned)||count($owned)>10)self::bad();
  $ids=[];foreach($owned as $row){if(!is_array($row))self::bad();self::id($row['image_id']??null);foreach(['receipt_hash','plan_hash'] as $k)if(!is_string($row[$k]??null)||!preg_match('/^[a-f0-9]{64}$/D',$row[$k]))self::bad();$ids[$row['image_id']]=true;}
  if($expected['operation']==='IMAGE_ARCHIVE'){
   if(!isset($ids[$expected['input']['image_id']])||$data!==[])self::bad();
   return ['image_id'=>$expected['input']['image_id'],'archived'=>true,'ownership_verified'=>true];
  }
  $page=$expected['input']['page'];$limit=$expected['input']['limit'];
  if(!is_array($data['data']??null)||!array_is_list($data['data'])||count($data['data'])>$limit||($data['current_page']??null)!==$page||($data['per_page']??null)!==$limit||!is_int($data['last_page']??null)||$data['last_page']<$page||$data['last_page']>2147483647)self::bad();
  $rows=[];$seen=[];foreach($data['data'] as $row){if(!is_array($row))self::bad();self::id($row['id']??null);if(isset($seen[$row['id']]))self::bad();$seen[$row['id']]=true;if(!isset($ids[$row['id']]))continue;if(array_key_exists('shop_id',$row)&&((!is_string($row['shop_id'])&&!is_int($row['shop_id']))||(string)$row['shop_id']!==$expected['provider_shop_id']))self::bad();
   $out=['id'=>$row['id']];foreach(['height','width','size'] as $k){if(!is_int($row[$k]??null)||$row[$k]<1||$row[$k]>2147483647)self::bad();$out[$k]=$row[$k];}
   foreach(['file_name'=>255,'mime_type'=>100,'upload_time'=>100] as $k=>$max){if(!is_string($row[$k]??null)||strlen($row[$k])>$max||preg_match('/[\x00-\x1f\x7f]/',$row[$k]))self::bad();$out[$k]=$row[$k];}
   $rows[]=$out;
  }
  return ['data'=>$rows,'current_page'=>$page,'last_page'=>$data['last_page'],'per_page'=>$limit,'next_page'=>$page<$data['last_page']?['page'=>$page+1,'limit'=>$limit]:null,'pagination_followed'=>false,'other_upload_data_disclosed'=>false];
 }
 private static function id(mixed $id):void {if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))self::bad();}
 private static function bad():never {throw new \InvalidArgumentException('Exact bounded owned upload contract required.');}
}
