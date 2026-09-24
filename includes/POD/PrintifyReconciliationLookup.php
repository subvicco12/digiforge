<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
final class PrintifyReconciliationLookup {
 private $sender;
 public function __construct(?callable $sender=null){$this->sender=$sender??static fn(string $url,array $args)=>wp_remote_request($url,$args);}
 public function lookup(array $outcome,int $integrationId): array|WP_Error {
  $row=is_array($outcome['unknown']??null)?$outcome['unknown']:[];
  $action=(string)($row['action']??'');$fp=(string)($row['request_fingerprint']??'');$identity=json_decode((string)($row['reconciliation_identity']??''),true);
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN'||!in_array($action,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true)||!preg_match('/^[a-f0-9]{64}$/',$fp)||!is_array($identity)) return new WP_Error('digiforge_printify_reconciliation_binding','Bound UNKNOWN evidence required.',['status'=>409]);
  $shop=absint($identity['shop_id']??0);$external=trim((string)($identity['external_reference']??''));
  if($shop<1||$external==='') return new WP_Error('digiforge_printify_reconciliation_identity','Bound shop and order identity required.',['status'=>409]);
  $sender=$this->sender;
  return (new PrintifyScopedCredentialRetriever())->consume($integrationId,static function(string $token) use($sender,$shop,$external,$action,$fp){
   $args=['method'=>'GET','headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],'timeout'=>20,'redirection'=>0,'sslverify'=>true];
   $match=null;$page=1;$lastPage=1;$maxPages=10;$pagesChecked=0;
   do{
    $response=$sender('https://api.printify.com/v1/shops/'.$shop.'/orders.json?limit=100&page='.$page,$args);
    if(is_wp_error($response)){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked]; }
    $status=(int)wp_remote_retrieve_response_code($response);
    if($status!==200){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'http_status'=>$status,'pages_checked'=>$pagesChecked]; }
    $body=(string)wp_remote_retrieve_body($response);if(strlen($body)>1048576){$token='';return new WP_Error('digiforge_printify_reconciliation_response','Provider evidence exceeds bounded size.',['status'=>409]);}
    $decoded=json_decode($body,true);if(!is_array($decoded)){ $token=''; return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pages_checked'=>$pagesChecked]; }
    $orders=is_array($decoded['data']??null)?$decoded['data']:[];$lastPage=max(1,(int)($decoded['last_page']??1));$pagesChecked++;
    foreach($orders as $order){if(!is_array($order))continue;$candidate=$action==='PROVIDER_ORDER_SUBMIT'?trim((string)($order['external_id']??'')):trim((string)($order['id']??''));if($candidate!==''&&hash_equals($external,$candidate)){$match=$order;break;}}
    if(is_array($match))break;$page++;
   }while($page<=$lastPage&&$page<=$maxPages);
   $token='';
   if(!is_array($match)&&$lastPage>$maxPages)return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'pagination_required'=>true,'pages_checked'=>$pagesChecked];
   if(!is_array($match)) return ['state'=>'PRINTIFY_RECONCILIATION_NOT_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp];
   $providerId=trim((string)($match['id']??''));if($providerId===''||preg_match('/^[A-Za-z0-9_-]{1,191}$/',$providerId)!==1)return ['state'=>'PRINTIFY_RECONCILIATION_UNKNOWN','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp];$sent=trim((string)($match['sent_to_production_at']??''));
   if($action==='PROVIDER_PRODUCTION_AUTHORIZE'&&$sent==='') return ['state'=>'PRINTIFY_RECONCILIATION_NOT_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>true,'request_fingerprint'=>$fp,'provider_order_id'=>$providerId];
   return ['state'=>'PRINTIFY_RECONCILIATION_CONFIRMED','retry_permitted'=>false,'reconciliation_required'=>false,'request_fingerprint'=>$fp,'provider_order_id'=>$providerId,'provider_status'=>sanitize_key((string)($match['status']??''))];
  });
 }
}
