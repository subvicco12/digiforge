<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\Security\ShopAccess;

/** Stored terminal failures only. This projection grants no retry or external authority. */
final class HistoricalFailurePanel
{
    public static function snapshot(string $shop, int $limit=50):array
    {
        $result=['query_state'=>'UNAVAILABLE','items'=>[],'truncated'=>false,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
        $canonical=array_values(array_diff(array_keys(ShopOperationsReadModel::shops()),['all']));
        if(!is_user_logged_in()||!current_user_can('manage_digiforge')||($shop!=='all'&&!in_array($shop,$canonical,true)))return $result;
        $required=$shop==='all'?$canonical:[$shop];
        foreach($required as $key)if(is_wp_error(ShopAccess::authorize($key,'manage_digiforge')))return $result;
        global $wpdb;$predicates=[];
        foreach($required as $key)$predicates[]=$wpdb->prepare('(BINARY history_listing.shop_reference=BINARY %s AND '.EtsyStoredShopScope::matches($wpdb->prepare('%s',$key),'history_operation.shop_reference','history_listing.environment').')',$key);
        $limit=max(1,min(100,$limit));$wpdb->last_error='';
        $rows=$wpdb->get_results('SELECT history_operation.id,history_listing.shop_reference shop_key,history_operation.operation_type,history_operation.state,history_operation.request_fingerprint,history_operation.evidence_hash,history_operation.updated_at FROM '.$wpdb->prefix.'digiforge_etsy_operations history_operation INNER JOIN '.Tables::etsy_draft_packages().' history_package ON history_package.id=history_operation.draft_package_id INNER JOIN '.Tables::listings().' history_listing ON history_listing.id=history_package.listing_id WHERE BINARY history_operation.state=BINARY \'CONFIRMED_FAILURE\' AND ('.implode(' OR ',$predicates).') ORDER BY history_operation.id DESC LIMIT '.($limit+1),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>$limit+1)return $result;
        foreach($rows as $row){
            $at=(string)($row['updated_at']??'');$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$at,new \DateTimeZone('UTC'));
            if((int)($row['id']??0)<1||!in_array($row['shop_key']??null,$required,true)||($row['state']??'')!=='CONFIRMED_FAILURE'||!preg_match('/^[A-Z_]{3,64}$/',(string)($row['operation_type']??''))||!preg_match('/^[a-f0-9]{64}$/',(string)($row['request_fingerprint']??''))||!preg_match('/^[a-f0-9]{64}$/',(string)($row['evidence_hash']??''))||$date===false||$date->format('Y-m-d H:i:s')!==$at)return $result;
        }
        $result['query_state']='AVAILABLE';$result['truncated']=count($rows)>$limit;$result['items']=array_slice($rows,0,$limit);return $result;
    }

    /** Hash-verified terminal POD failures with exact consumed package/order ancestry. */
    public static function providerSnapshot(string $shop, int $limit=50):array
    {
        $result=['query_state'=>'UNAVAILABLE','items'=>[],'truncated'=>false,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
        $canonical=array_values(array_diff(array_keys(ShopOperationsReadModel::shops()),['all']));
        if(!is_user_logged_in()||!current_user_can('manage_digiforge')||($shop!=='all'&&!in_array($shop,$canonical,true)))return $result;
        $required=$shop==='all'?$canonical:[$shop];foreach($required as $key)if(is_wp_error(ShopAccess::authorize($key,'manage_digiforge')))return $result;
        global $wpdb;$predicates=[];$cases=[];foreach($required as $key){$predicate=EtsyStoredShopScope::orderPredicate($key,'history_order');$predicates[]=$predicate;$cases[]='WHEN '.$predicate.' THEN '.$wpdb->prepare('%s',$key);}
        $limit=max(1,min(100,$limit));$wpdb->last_error='';
        $rows=$wpdb->get_results('SELECT history_failure.*,history_binding.package_id,history_binding.package_hash binding_package_hash,history_binding.nonce_hash binding_nonce_hash,history_package.package_hash current_package_hash,history_package.order_id,history_nonce.authorization_hash consumed_authorization_hash,history_claim.outcome_type,history_claim.outcome_hash,CASE '.implode(' ',$cases).' ELSE NULL END shop_key FROM '.Tables::pod_execution_failures().' history_failure INNER JOIN '.Tables::pod_authorization_bindings().' history_binding ON BINARY history_binding.authorization_hash=BINARY history_failure.authorization_hash INNER JOIN '.Tables::pod_authorization_packages().' history_package ON history_package.id=history_binding.package_id INNER JOIN '.Tables::orders().' history_order ON history_order.id=history_package.order_id LEFT JOIN '.Tables::pod_execution_nonces().' history_nonce ON BINARY history_nonce.nonce_hash=BINARY history_failure.nonce_hash AND BINARY history_nonce.authorization_hash=BINARY history_failure.authorization_hash LEFT JOIN '.Tables::pod_execution_outcomes().' history_claim ON BINARY history_claim.authorization_hash=BINARY history_failure.authorization_hash WHERE ('.implode(' OR ',$predicates).') ORDER BY history_failure.id DESC LIMIT '.($limit+1),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!==''||count($rows)>$limit+1)return $result;
        $items=[];
        foreach($rows as $row){
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row['recorded_at'],new \DateTimeZone('UTC'));
            foreach(['evidence_hash','authorization_hash','nonce_hash','failure_hash','binding_package_hash','current_package_hash','binding_nonce_hash','outcome_hash']as $field)if(!preg_match('/^[a-f0-9]{64}$/',(string)($row[$field]??'')))return $result;
            if($date===false||$date->format('Y-m-d H:i:s')!==$row['recorded_at']||$date->getTimestamp()<1||(int)$row['id']<1||(int)$row['executed_by']<1||(int)$row['order_id']<1||!in_array($row['shop_key'],$required,true)||!hash_equals($row['binding_package_hash'],$row['current_package_hash'])||!hash_equals($row['nonce_hash'],$row['binding_nonce_hash'])||!hash_equals($row['authorization_hash'],(string)$row['consumed_authorization_hash'])||$row['outcome_type']!=='FAILED'||!hash_equals($row['outcome_hash'],$row['failure_hash'])||!preg_match('/^[A-Z_]{3,64}$/',(string)$row['action'])||!preg_match('/^[a-z0-9_-]{1,64}$/',(string)$row['failure_category'])||!preg_match('/^[a-z0-9_-]{1,100}$/D',(string)$row['failure_code']))return $result;
            // Reconstruct exactly the canonical payload written by ExecutionFailureRecord.
            $payload=['action'=>$row['action'],'evidence_hash'=>$row['evidence_hash'],'authorization_hash'=>$row['authorization_hash'],'authorization_nonce_hash'=>$row['nonce_hash'],'executed_by'=>(int)$row['executed_by'],'recorded_at'=>$date->getTimestamp(),'adapter_status'=>'FAILED','failure_category'=>$row['failure_category'],'failure_code'=>$row['failure_code'],'nonce_consumed'=>true,'retry_permitted'=>false];ksort($payload);
            if(!hash_equals($row['failure_hash'],hash('sha256',(string)wp_json_encode($payload))))return $result;
            $outcome=\DigiForge\POD\ExecutionOutcomeRepository::findByAuthorizationHash($row['authorization_hash']);
            if(is_wp_error($outcome)||($outcome['state']??'')!=='EXECUTION_FAILED'||(int)($outcome['failure']['id']??0)!==(int)$row['id'])return $result;
            $items[]=['id'=>(int)$row['id'],'order_id'=>(int)$row['order_id'],'package_id'=>(int)$row['package_id'],'shop_key'=>$row['shop_key'],'action'=>$row['action'],'failure_category'=>$row['failure_category'],'failure_code'=>$row['failure_code'],'recorded_at'=>$row['recorded_at'],'failure_hash'=>$row['failure_hash'],'evidence_hash'=>$row['evidence_hash']];
        }
        $result['query_state']='AVAILABLE';$result['truncated']=count($items)>$limit;$result['items']=array_slice($items,0,$limit);return $result;
    }

    private static function renderProvider(string $shop):void
    {
        $snapshot=self::providerSnapshot($shop);echo '<section class="df-panel"><h2>Historical failed POD executions</h2><p>Hash-verified retained failure records and exact consumed package/order ancestry. Local evidence does not certify provider success or authorize a retry. External execution authority: NO.</p>';
        if($snapshot['query_state']!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">POD historical failure evidence unavailable or inconsistent. No empty or cleared history is inferred.</div></section>';return;}
        if($snapshot['items']===[])echo '<div class="df-empty">No bound POD terminal failures in the bounded authorized window. Unbound legacy records require separate provenance reconciliation.</div>';
        else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Historical POD terminal failures"><table class="df-table"><thead><tr><th>Failure</th><th>Shop</th><th>Order</th><th>Package</th><th>Action</th><th>Category</th><th>Code</th><th>Failure SHA-256</th><th>Evidence SHA-256</th><th>Recorded UTC</th><th>Retry authority</th></tr></thead><tbody>';foreach($snapshot['items']as $row){echo '<tr>';foreach(['id','shop_key','order_id','package_id','action','failure_category','failure_code','failure_hash','evidence_hash','recorded_at']as $key)echo '<td>'.esc_html((string)$row[$key]).'</td>';echo '<td>NO</td></tr>';}echo '</tbody></table></div>';}
        if($snapshot['truncated'])echo '<p>POD failure window truncated; additional authorized terminal failures exist.</p>';echo '</section>';
    }

    public static function render(string $shop):void
    {
        self::renderProvider($shop);
        $snapshot=self::snapshot($shop);
        echo '<section class="df-panel"><h2>Historical failed Etsy operations</h2><p>Stored terminal CONFIRMED_FAILURE evidence. Recorded hashes identify retained request/evidence; this view does not certify external evidence or grant retry authority. External execution authority: NO.</p>';
        if($snapshot['query_state']!=='AVAILABLE'){echo '<div class="df-notice df-notice-error">Historical failure evidence unavailable. No empty or cleared history is inferred.</div></section>';return;}
        if($snapshot['items']===[])echo '<div class="df-empty">No terminal failures in the bounded authorized window.</div>';
        else{
            echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Historical terminal operation failures"><table class="df-table"><thead><tr><th>Operation</th><th>Shop</th><th>Action</th><th>State</th><th>Request SHA-256</th><th>Evidence SHA-256</th><th>Recorded UTC</th><th>Retry authority</th></tr></thead><tbody>';
            foreach($snapshot['items']as $row){echo '<tr>';foreach(['id','shop_key','operation_type','state','request_fingerprint','evidence_hash','updated_at']as $key)echo '<td>'.esc_html((string)$row[$key]).'</td>';echo '<td>NO</td></tr>';}
            echo '</tbody></table></div>';
        }
        if($snapshot['truncated'])echo '<p>Historical failure window truncated; additional authorized terminal failures exist.</p>';
        echo '</section>';
    }
}
