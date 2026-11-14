<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
final class PrintifyReconciliationLookup {
 private $sender;
 public function __construct(?callable $sender=null){$this->sender=$sender??static fn(string $url,array $args)=>wp_remote_request($url,$args);}
 public function lookup(array $outcome,int $integrationId): array|WP_Error {
  return new WP_Error('printify_governed_reconciliation_required','Collect independently approved order-read receipts, then reconcileReadReceipts locally.',['status'=>409,'retry_permitted'=>false]);
  $row=is_array($outcome['unknown']??null)?$outcome['unknown']:[];
  $action=(string)($row['action']??'');$fp=(string)($row['request_fingerprint']??'');$identity=json_decode((string)($row['reconciliation_identity']??''),true);
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN'||!in_array($action,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true)||!preg_match('/^[a-f0-9]{64}$/',$fp)||!is_array($identity)) return new WP_Error('digiforge_printify_reconciliation_binding','Bound UNKNOWN evidence required.',['status'=>409]);
  if(($identity['integration_id']??null)!==$integrationId)return PrintifyConnectionBoundary::error();
  $shop=absint($identity['shop_id']??0);$external=trim((string)($identity['external_reference']??''));
  if($shop<1||$external==='') return new WP_Error('digiforge_printify_reconciliation_identity','Bound shop and order identity required.',['status'=>409]);
  $scope=is_array($identity['connection_scope']??null)?$identity['connection_scope']:[];
  $sender=$this->sender;
  return (new PrintifyScopedCredentialRetriever())->consume($integrationId,static function(string $token) use($sender,$shop,$external,$action,$fp,$integrationId,$scope){
   $args=['method'=>'GET','headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','User-Agent'=>'DigiForge/PrintifyApi'],'timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1048577];
   $match=null;$page=1;$lastPage=1;$maxPages=10;$pagesChecked=0;
   do{
    $binding=PrintifyConnectionBoundary::runtime($integrationId,$action,$scope,(string)$shop);if(is_wp_error($binding))return $binding;
    $endpoint='/v1/shops/'.$shop.'/orders.json?limit=10&page='.$page;$correlation='reconcile:'.$fp.':'.bin2hex(random_bytes(8)).':'.$page;
    $quota=PrintifyAccountQuota::reserve($scope,$endpoint,'GET',$correlation);if(is_wp_error($quota))return $quota;
    try{$response=$sender('https://api.printify.com'.$endpoint,$args);}catch(\Throwable){$response=new WP_Error('printify_read_transport');}
    $quotaObserved=PrintifyAccountQuota::observe($scope,is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response),is_wp_error($response)?[]:['retry-after'=>(string)wp_remote_retrieve_header($response,'retry-after')],$correlation);if(is_wp_error($quotaObserved))return $quotaObserved;

    if(is_wp_error($response)){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked]; }
    $binding=PrintifyConnectionBoundary::runtime($integrationId,$action,$scope,(string)$shop);if(is_wp_error($binding))return $binding;
    $status=(int)wp_remote_retrieve_response_code($response);
    if($status!==200){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'http_status'=>$status,'pages_checked'=>$pagesChecked]; }
    $body=(string)wp_remote_retrieve_body($response);if(strlen($body)>1048576){$token='';return new WP_Error('digiforge_printify_reconciliation_response','Provider evidence exceeds bounded size.',['status'=>409]);}
    $decoded=json_decode($body,true);if(!is_array($decoded)){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked]; }
    $orders=is_array($decoded['data']??null)?$decoded['data']:[];$advertisedLastPage=max(1,(int)($decoded['last_page']??1));$lastPage=max($lastPage,$advertisedLastPage);$pagesChecked++;
    foreach($orders as $order){if(!is_array($order))continue;$candidate=$action==='PROVIDER_ORDER_SUBMIT'?trim((string)($order['external_id']??'')):trim((string)($order['id']??''));if($candidate!==''&&hash_equals($external,$candidate)){$match=$order;break;}}
    if(is_array($match))break;$page++;
   }while($page<=$lastPage&&$page<=$maxPages);
   $token='';
   if(!is_array($match)&&$lastPage>$maxPages)return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pagination_required'=>true,'pages_checked'=>$pagesChecked];
   if(!is_array($match)) return ['state'=>'PRINTIFY_RECONCILIATION_NOT_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked];
   $providerId=trim((string)($match['id']??''));if($providerId===''||preg_match('/^[A-Za-z0-9_-]{1,191}$/',$providerId)!==1)return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked];$sent=trim((string)($match['sent_to_production_at']??''));
   if($action==='PROVIDER_PRODUCTION_AUTHORIZE'&&$sent==='') return ['state'=>'PRINTIFY_RECONCILIATION_NOT_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'provider_order_id'=>$providerId,'pages_checked'=>$pagesChecked];
   return ['state'=>'PRINTIFY_RECONCILIATION_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>false,'request_fingerprint'=>$fp,'provider_order_id'=>$providerId,'provider_status'=>sanitize_key((string)($match['status']??'')),'pages_checked'=>$pagesChecked];
  },$scope);
 }
}
