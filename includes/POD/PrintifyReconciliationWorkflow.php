<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
final class PrintifyReconciliationWorkflow {
 public function resolution(string $authorizationHash):array|WP_Error{
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));if(is_wp_error($outcome))return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN')return new WP_Error('digiforge_printify_reconciliation_state','Persisted UNKNOWN execution required.',['status'=>409]);
  $u=is_array($outcome['unknown']??null)?$outcome['unknown']:[];return PrintifyReconciliationRepository::latest((string)($u['unknown_hash']??''));
 }
 public function reconcile(string $authorizationHash,int $integrationId): array|WP_Error {
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));
  if(is_wp_error($outcome)) return $outcome;
  if(($outcome['state']??'')!=='EXECUTION_UNKNOWN') return new WP_Error('digiforge_printify_reconciliation_state','Persisted UNKNOWN execution required.',['status'=>409]);
  $row=is_array($outcome['unknown']??null)?$outcome['unknown']:[];$identity=json_decode((string)($row['reconciliation_identity']??''),true);
  $boundIntegration=absint(is_array($identity)?($identity['integration_id']??0):0);
  if($boundIntegration<1||$integrationId!==$boundIntegration)return new WP_Error('digiforge_printify_reconciliation_integration','Reconciliation integration must match persisted UNKNOWN evidence.',['status'=>409]);
  $result=(new PrintifyReconciliationLookup())->lookup($outcome,$boundIntegration);if(is_wp_error($result))return $result;
  return PrintifyReconciliationRepository::save($outcome,$result,$boundIntegration);
 }
 /** Page-number API observations are not an atomic provider snapshot or causal execution proof. */
 public static function pageCoverage(array $receipts):bool {
  if(!array_is_list($receipts)||count($receipts)<1||count($receipts)>100)return false;$pages=[];$ids=[];$expectedLast=null;$expectedPer=null;
  foreach($receipts as $r){if(($r['operation']??null)!=='ORDERS_READ'||!is_array($r['data']??null))return false;$d=$r['data'];$page=$d['current_page']??null;$last=$d['last_page']??null;$per=$d['per_page']??null;
   if(!is_int($page)||!is_int($last)||!is_int($per)||$page<1||$page>$last||$last>100||$per<1||$per>10||isset($pages[$page])||($expectedLast!==null&&$expectedLast!==$last)||($expectedPer!==null&&$expectedPer!==$per)||!is_array($d['data']??null)||!array_is_list($d['data']))return false;
   $n=count($d['data']);if($n>$per||($page<$last&&$n!==$per)||($last>1&&$page===$last&&$n===0))return false;
   foreach($d['data'] as $row){if(!is_string($row['id']??null)||isset($ids[$row['id']]))return false;$ids[$row['id']]=true;}$pages[$page]=true;$expectedLast=$last;$expectedPer=$per;
  }
  return count($pages)===$expectedLast&&isset($pages[1],$pages[$expectedLast]);
 }
 /** No network. Consumes fresh governed receipts and never restores execution authority. */
 public function reconcileReadReceipts(string $authorizationHash,array $receiptHashes):array|WP_Error {
  if(!array_is_list($receiptHashes)||count($receiptHashes)<1||count($receiptHashes)>100)return new WP_Error('printify_reconciliation_read_budget','One to one hundred exact receipts required.',['status'=>409]);
  $outcome=ExecutionOutcomeRepository::findByAuthorizationHash(strtolower(trim($authorizationHash)));if(is_wp_error($outcome)||($outcome['state']??null)!=='EXECUTION_UNKNOWN')return new WP_Error('printify_reconciliation_unknown','Persisted provider UNKNOWN required.',['status'=>409]);
  $u=$outcome['unknown'];$identity=json_decode($u['reconciliation_identity']??'',true);$scope=$identity['connection_scope']??null;$shop=is_array($scope)?($scope['shop_key']??''):'';if(!is_string($shop)||!in_array($shop,['personalized_pod','standard_pod'],true))return new WP_Error('printify_reconciliation_scope','Canonical provider shop required.',['status'=>403]);
  if(!is_array($scope)||!in_array($u['action']??null,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true)||\DigiForge\PortalAccess\Owner::require($shop)!==true)return new WP_Error('printify_reconciliation_scope','Exact current owner and provider scope required.',['status'=>403]);
  foreach(['manage_digiforge_connections','manage_digiforge_orders'] as $cap)if(!current_user_can($cap)||\DigiForge\Security\ShopAccess::authorize($shop,$cap)!==true)return new WP_Error('printify_reconciliation_scope','Exact order scope required.',['status'=>403]);
  $matches=[];$pages=0;$pagination=false;$accepted=[];$read=new PrintifyApiReadWorkflow();
  foreach($receiptHashes as $hash){if(!is_string($hash))return new WP_Error('printify_reconciliation_receipt','Exact receipt hashes required.',['status'=>409]);$r=$read->receipt($hash);if(is_wp_error($r)||$r['status']!=='SUCCEEDED'||$r['shop_key']!==$shop||$r['integration_id']!==$identity['integration_id']||($r['provider_shop_id']??null)!==(string)$identity['shop_id']||($r['external_account_hash']??null)!==hash('sha256',$scope['external_account_reference'])||strtotime($r['observed_at'])<time()-300)return new WP_Error('printify_reconciliation_receipt','Current exact source receipts required.',['status'=>409]);
   if($r['operation']==='ORDER_READ')$orders=[$r['data']];elseif($r['operation']==='ORDERS_READ'){$orders=$r['data']['data'];$pagination=true;}else return new WP_Error('printify_reconciliation_receipt','Only order evidence accepted.',['status'=>409]);$pages++;$accepted[]=$r;
   foreach($orders as $order){$candidate=$u['action']==='PROVIDER_ORDER_SUBMIT'?($order['external_id']??null):$order['id'];if($candidate!==$identity['external_reference'])continue;$key=$order['id'];if(isset($matches[$key])&&$matches[$key]!==$order)return new WP_Error('printify_reconciliation_conflict','Conflicting observations remain unknown.',['status'=>409]);$matches[$key]=$order;}
  }
  $complete=self::pageCoverage($accepted);$confirmed=count($matches)===1&&($u['action']!=='PROVIDER_ORDER_SUBMIT'||$complete);$match=$confirmed?array_values($matches)[0]:null;if($confirmed&&$u['action']==='PROVIDER_PRODUCTION_AUTHORIZE'&&empty($match['sent_to_production_at']))$confirmed=false;
  $result=['state'=>$confirmed?'PRINTIFY_RECONCILIATION_CONFIRMED':(count($matches)>1?'PRINTIFY_RECONCILIATION_UNKNOWN':'PRINTIFY_RECONCILIATION_NOT_CONFIRMED'),'request_fingerprint'=>$u['request_fingerprint'],'provider_order_id'=>$confirmed?$match['id']:'','provider_status'=>$confirmed?$match['status']:'','pages_checked'=>$pages,'pagination_required'=>$pagination&&!$confirmed,'retry_permitted'=>false,'reconciliation_required'=>!$confirmed,'source_receipt_hashes'=>$receiptHashes];
  $durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return $durable;global $wpdb;$table=\DigiForge\Database\Tables::pod_printify_reconciliations();$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(strtoupper((string)$engine)!=='INNODB')return new WP_Error('printify_reconciliation_storage','Durable existing reconciliation storage required.',['status'=>503]);
  if($wpdb->query('START TRANSACTION')===false)return new WP_Error('printify_reconciliation_storage','Transaction unavailable.',['status'=>503]);
  try{$saved=PrintifyReconciliationRepository::save($outcome,$result,$identity['integration_id']);if(is_wp_error($saved)||!\DigiForge\Security\Logger::write('printify_reconciliation_read_receipts',['authorization_hash'=>$authorizationHash,'receipt_hashes'=>$receiptHashes,'state'=>$result['state']],'printify_reconciliation',$u['unknown_hash'])){$wpdb->query('ROLLBACK');return new WP_Error('printify_reconciliation_audit','Reconciliation evidence unavailable.',['status'=>503]);}if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('printify_reconciliation_commit','Reconciliation commit unknown.',['status'=>503]);}return $saved;}catch(\Throwable){$wpdb->query('ROLLBACK');return new WP_Error('printify_reconciliation_store','Reconciliation evidence unavailable.',['status'=>503]);}
 }
}
