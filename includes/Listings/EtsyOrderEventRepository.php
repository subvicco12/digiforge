<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use WP_Error;

/** Immutable minimal event metadata; raw buyer tracking numbers are never stored here. */
final class EtsyOrderEventRepository
{
    public function record(array $receipt,array $result):array|WP_Error
    {
        global $wpdb;
        if(($result['state']??'')!=='ETSY_ORDER_EVENT_RECORDED'||!in_array($result['event_type']??'',['ORDER.SHIPPED','ORDER.DELIVERED'],true))return self::error();
        if(($receipt['provider']??'')!=='etsy'||($receipt['verification_status']??'')!=='VERIFIED'||($result['event_id']??null)!==($receipt['event_id']??null)||($result['shop_reference']??null)!==($receipt['shop_reference']??null)||strtoupper((string)($receipt['event_type']??''))!==$result['event_type']||($result['environment']??'')!=='production'||!preg_match('/^[a-f0-9]{64}$/D',(string)($receipt['body_sha256']??'')))return self::error();
        $resultJson=wp_json_encode($result);if(!is_string($resultJson))return self::error();
        $proof=['version'=>1,'event_id'=>$receipt['event_id'],'order_id'=>(int)$result['order_id'],'external_order_reference'=>$result['external_order_reference'],'shop_reference'=>$result['shop_reference'],'environment'=>$result['environment'],'event_type'=>$result['event_type'],'body_sha256'=>$receipt['body_sha256'],'result_json'=>$resultJson];
        $canonical=Validator::canonicalJson($proof);
        $data=['event_id'=>$proof['event_id'],'order_id'=>$proof['order_id'],'external_order_reference'=>$proof['external_order_reference'],'shop_reference'=>$proof['shop_reference'],'environment'=>$proof['environment'],'event_type'=>$proof['event_type'],'body_sha256'=>$proof['body_sha256'],'result_hash'=>hash('sha256',$resultJson),'canonical_evidence'=>$canonical,'evidence_hash'=>hash('sha256',$canonical),'received_at'=>$receipt['received_at']];
        $wpdb->last_error='';$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_order_event_evidence().' WHERE event_id=%s',$data['event_id']),ARRAY_A);if($wpdb->last_error!=='')return self::error();
        if(!is_array($old)){$wpdb->last_error='';$wpdb->insert(Tables::etsy_order_event_evidence(),$data);$wpdb->last_error='';$old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_order_event_evidence().' WHERE event_id=%s',$data['event_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($old))return self::error();}
        foreach($data as $key=>$value)if((string)($old[$key]??'')!==(string)$value)return self::error();
        return $old;
    }
    /** Only admitted private operators can query the bounded receipt projection. */
    public function recent(?string $shop=null,int $limit=50):array
    {
        $deny=['query_state'=>'UNAVAILABLE','items'=>[],'external_execution_authorized'=>false];
        if(!is_user_logged_in()||!current_user_can('manage_digiforge'))return $deny;
        $allowed=\DigiForge\Security\ShopAccess::allowedShops('manage_digiforge_orders');if($allowed instanceof WP_Error||($shop===null?count($allowed)!==4:!in_array($shop,$allowed,true)))return $deny;
        global $wpdb;$predicates=[];foreach($shop===null?$allowed:[$shop]as $canonical)$predicates[]=EtsyStoredShopScope::orderPredicate($canonical,'e');$scope=' WHERE ('.implode(' OR ',$predicates).')';$limit=max(1,min(100,$limit));
        $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT e.*,w.provider AS receipt_provider,w.event_type AS receipt_type,w.shop_reference AS receipt_shop,w.body_sha256 AS receipt_body,w.result_hash AS receipt_result,w.verification_status,w.processing_status,w.received_at AS receipt_received,o.external_order_reference AS current_external,o.shop_reference AS current_shop,o.environment AS current_environment,o.channel AS current_channel FROM '.Tables::etsy_order_event_evidence().' e LEFT JOIN '.Tables::webhook_evidence().' w ON BINARY w.event_id=BINARY e.event_id AND BINARY w.provider=BINARY %s LEFT JOIN '.Tables::orders().' o ON o.id=e.order_id'.$scope.' ORDER BY e.id DESC LIMIT %d','etsy',$limit),ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')return $deny;
        $items=[];foreach($rows as $row){$item=self::verified($row);if($item===null)return $deny;$items[]=$item;}
        return ['query_state'=>'AVAILABLE','items'=>$items,'external_execution_authorized'=>false];
    }
    private static function verified(array $row):?array
    {
        try{
            $raw=$row['canonical_evidence'];if(!is_string($raw)||strlen($raw)>8192||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['evidence_hash'])||!hash_equals($row['evidence_hash'],hash('sha256',$raw)))return null;
            $proof=json_decode($raw,true,12,JSON_THROW_ON_ERROR);if(!is_array($proof)||Validator::canonicalJson($proof)!==$raw||($proof['version']??null)!==1)return null;
            foreach(['event_id','order_id','external_order_reference','shop_reference','environment','event_type','body_sha256']as $key)if((string)($proof[$key]??'')!==(string)$row[$key])return null;
            if(($row['verification_status']??'')!=='VERIFIED'||($row['processing_status']??'')!=='PROCESSED'||$row['receipt_provider']!=='etsy'||strtoupper($row['receipt_type'])!==$row['event_type']||$row['receipt_shop']!==$row['shop_reference']||$row['receipt_body']!==$row['body_sha256']||$row['receipt_result']!==$row['result_hash']||$row['receipt_received']!==$row['received_at'])return null;
            if($row['current_channel']!=='etsy'||$row['current_environment']!=='production'||$row['environment']!=='production'||$row['current_external']!==$row['external_order_reference']||$row['current_shop']!==$row['shop_reference'])return null;
            $resultJson=$proof['result_json']??null;if(!is_string($resultJson)||!hash_equals($row['result_hash'],hash('sha256',$resultJson)))return null;
            $result=json_decode($resultJson,true,12,JSON_THROW_ON_ERROR);
            foreach(['event_id','order_id','external_order_reference','shop_reference','environment','event_type']as $key)if((string)($result[$key]??'')!==(string)$row[$key])return null;
            $metadata=$result['evidence']??null;if(!is_array($metadata)||($result['state']??'')!=='ETSY_ORDER_EVENT_RECORDED'||!in_array($row['event_type'],['ORDER.SHIPPED','ORDER.DELIVERED'],true)||($metadata['event_type']??'')!==$row['event_type']||($metadata['authoritative_fulfillment_state']??null)!==false||($metadata['fulfillment_authorized']??null)!==false||($metadata['external_execution_performed']??null)!==false||($result['fulfillment_authorized']??null)!==false||($result['external_execution_performed']??null)!==false)return null;
            if(!is_bool($metadata['tracking_present']??null)||!is_bool($metadata['carrier_present']??null)||!is_string($metadata['carrier']??null)||strlen($metadata['carrier'])>400||!is_string($metadata['tracking_fingerprint']??null)||($metadata['tracking_present']?!preg_match('/^[a-f0-9]{64}$/D',$metadata['tracking_fingerprint']):$metadata['tracking_fingerprint']!==''))return null;
            return ['event_id'=>$row['event_id'],'order_id'=>(int)$row['order_id'],'external_order_reference'=>$row['external_order_reference'],'shop_reference'=>$row['shop_reference'],'event_type'=>$row['event_type'],'body_sha256'=>$row['body_sha256'],'evidence_hash'=>$row['evidence_hash'],'received_at'=>$row['received_at']]+$metadata;
        }catch(\Throwable){return null;}
    }
    private static function error():WP_Error{return new WP_Error('digiforge_etsy_order_event_evidence_unavailable','Normalized shipment/delivery receipt evidence could not be verified or persisted.',['status'=>503,'reconciliation_required'=>true,'external_execution_performed'=>false]);}
}
