<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** No transport or authority: bounded explicit request plans and unverified receipt comparisons. */
final class PrintifyCatalogResearch
{
 public static function requests(int $blueprint=49,array $providers=[410,217,61]):array {
  if(!array_is_list($providers)||count($providers)<1||count($providers)>3||count(array_unique($providers,SORT_REGULAR))!==count($providers))throw new \InvalidArgumentException('One to three distinct provider identifiers required.');
  $requests=[['kind'=>'blueprint','blueprint_id'=>$blueprint],['kind'=>'providers','blueprint_id'=>$blueprint]];
  foreach($providers as $provider){$requests[]=['kind'=>'variants','blueprint_id'=>$blueprint,'provider_id'=>$provider];$requests[]=['kind'=>'shipping','blueprint_id'=>$blueprint,'provider_id'=>$provider];}
  foreach($requests as $request)PrintifyCatalogReadContract::endpoint($request);
  return $requests;
 }
 /** Input must be independently authorized repository receipts; this projection grants no authority. */
 public static function compare(array $receipts,int $blueprint=49,array $providers=[410,217,61]):array {
  $requests=self::requests($blueprint,$providers);if(!array_is_list($receipts)||count($receipts)>10)throw new \InvalidArgumentException('Bounded receipt list required.');
  $allowed=[];foreach($requests as $r)$allowed[PrintifyCatalogReadContract::endpoint($r)]=true;
  $out=['blueprint_id'=>$blueprint,'state'=>'DRAFT_UNVERIFIED','supplier_certified'=>false,'mutation_authorized'=>false,'pagination'=>'NO_AUTOMATIC_FOLLOW','providers'=>[]];$seen=[];$scope=null;
  foreach($receipts as $e){
   if(!is_array($e)||!is_array($e['request']??null)||($e['state']??null)!=='DRAFT_UNVERIFIED'||($e['supplier_certified']??null)!==false||($e['mutation_authorized']??null)!==false||!preg_match('/^[a-f0-9]{64}$/D',$e['response_sha256']??'')||!is_string($e['observed_at']??null)||strtotime($e['observed_at'])===false)throw new \InvalidArgumentException('Source-bound unverified receipt required.');
   if(!is_string($e['grant_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$e['grant_sha256']))throw new \InvalidArgumentException('Exact grant identity required.');
   $identity=[$e['grant_sha256'],$e['account_id']??null,$e['integration_id']??null,$e['environment']??null,$e['site_sha256']??null,$e['scope']??null];if($scope!==null&&$scope!==$identity)throw new \InvalidArgumentException('Cross-scope comparison denied.');$scope=$identity;
   $path=PrintifyCatalogReadContract::endpoint($e['request']);if(!isset($allowed[$path])||isset($seen[$path])||($e['endpoint_path']??null)!==$path)throw new \InvalidArgumentException('Missing, duplicate or foreign endpoint identity.');
   PrintifyCatalogReadContract::select($e['data'],$e['request']);if($e['request']['kind']==='variants'&&array_key_exists('id',$e['data'])&&$e['data']['id']!==$e['request']['provider_id'])throw new \InvalidArgumentException('Provider identity mismatch.');$seen[$path]=$e;
  }
  foreach($providers as $provider){$row=['provider_id'=>$provider,'variants_status'=>'UNKNOWN','shipping_methods_status'=>'UNKNOWN','safe_zone'=>'UNKNOWN','bleed'=>'UNKNOWN','dpi'=>'UNKNOWN','production_tolerance'=>'UNKNOWN','commercial_rights'=>'UNKNOWN','buyer_editability'=>'UNKNOWN','cost'=>'UNKNOWN','source_evidence'=>[]];
   foreach(['variants','shipping']as $kind){$request=['kind'=>$kind,'blueprint_id'=>$blueprint,'provider_id'=>$provider];$path=PrintifyCatalogReadContract::endpoint($request);if(!isset($seen[$path]))continue;$e=$seen[$path];
    if($kind==='variants'){$row['variants_status']='API_OBSERVATION';$row['variants']=$e['data']['variants'];$row['catalog_rectangles']=[];foreach($e['data']['variants']as $variant){$rectangles=PrintifyCatalogPreview::placeholders($variant);if($rectangles instanceof \WP_Error)throw new \InvalidArgumentException('Malformed catalog rectangle evidence.');$row['catalog_rectangles'][]=['variant_id'=>$variant['id'],'placeholders'=>$rectangles,'status'=>$rectangles===[]?'UNKNOWN':'CATALOG_RECTANGLES_ONLY'];}}
    else{$row['shipping_methods_status']='API_OBSERVATION';$row['shipping_methods']=$e['data']['data'];$row['destination_rates']='UNKNOWN_UNTIL_EXPLICIT_METHOD_READ';}
    $row['source_evidence'][]=['endpoint_path'=>$path,'observed_at'=>$e['observed_at'],'response_sha256'=>$e['response_sha256']];
   }$out['providers'][]=$row;
  }
  return $out;
 }
}
