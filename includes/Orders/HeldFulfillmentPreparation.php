<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\POD\BusinessScopeRepository;
use DigiForge\POD\ScopedProviderConnection;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Current stored evidence for local review only; no execution permit is issued. */
final class HeldFulfillmentPreparation
{
    public function forOrder(string $shop,int $orderId): array|WP_Error
    {
        if(!ShopAccessPolicy::validShop($shop)||$orderId<1)return self::error('scope','Select one exact shop and order.',400);
        foreach(['manage_digiforge_orders','manage_digiforge_pod'] as $cap){$access=ShopAccess::authorize($shop,$cap);if(is_wp_error($access))return $access;}
        global $wpdb;
        $orders=$this->rows($wpdb->prepare("SELECT o.id,o.channel,o.environment,o.external_order_reference,o.shop_reference,o.currency,o.personalization_required,o.state,o.approved_by,o.approved_at FROM ".Tables::orders()." o WHERE o.id=%d AND BINARY o.channel=BINARY 'etsy' AND ".EtsyStoredShopScope::orderPredicate($shop,'o'),$orderId));
        if(is_wp_error($orders))return $orders;
        if(count($orders)!==1)return self::error('order_scope','The exact order is unavailable in this shop.',404);
        $connection=$this->connection($shop,$orders[0]['environment']);if(is_wp_error($connection))return $connection;
        $lines=$this->rows($wpdb->prepare('SELECT li.id,li.listing_id,li.product_version_id,li.provider_mapping_id,li.quantity,li.currency,li.environment,li.validation_status,m.product_version_id AS mapping_product_version_id,m.provider,m.environment AS mapping_environment,m.state AS mapping_state,m.approved_by AS mapping_approved_by,m.approved_at AS mapping_approved_at,m.provider_product_key,m.provider_variant_key,m.mapping_version FROM '.Tables::order_line_items().' li LEFT JOIN '.Tables::pod_mappings().' m ON m.id=li.provider_mapping_id WHERE li.order_id=%d ORDER BY li.id LIMIT 101',$orderId));
        $personal=$this->rows($wpdb->prepare('SELECT ps.id,ps.order_line_item_id,ps.personalization_schema_id,ps.canonical_payload,ps.payload_hash,ps.review_status,ps.reviewed_by,ps.reviewed_at,s.product_version_id AS schema_product_version_id,s.state AS schema_state,s.field_definitions FROM '.Tables::personalization_submissions().' ps INNER JOIN '.Tables::order_line_items().' li ON li.id=ps.order_line_item_id LEFT JOIN '.Tables::personalization_schemas().' s ON s.id=ps.personalization_schema_id WHERE li.order_id=%d ORDER BY ps.order_line_item_id,ps.id LIMIT 101',$orderId));
        $plans=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_plans().' WHERE order_id=%d ORDER BY id DESC LIMIT 101',$orderId));
        $intents=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_intents().' WHERE order_id=%d ORDER BY id DESC LIMIT 101',$orderId));
        $renders=$this->rows($wpdb->prepare('SELECT id,provider_mapping_id,template_key,template_version,personalization_evidence_hash,review_status,reviewed_by,reviewed_at FROM '.Tables::pod_render_evidence().' WHERE order_id=%d ORDER BY id DESC LIMIT 101',$orderId));
        foreach([$lines,$personal,$plans,$intents,$renders] as $rows){if(is_wp_error($rows))return $rows;}
        $identities=[];
        foreach($lines as $line){
            $mapping=$this->rows($wpdb->prepare('SELECT id,product_version_id,production_plan_id,provider,environment,provider_product_key,provider_variant_key,mapping_version,state,approved_by,approved_at FROM '.Tables::pod_mappings().' WHERE id=%d',(int)$line['provider_mapping_id']));
            if(is_wp_error($mapping))return $mapping;
            if(count($mapping)!==1)return self::error('mapping','An exact stored POD mapping is required for each held line.');
            $owner=(new BusinessScopeRepository())->assertActiveOwnershipForMapping((int)$line['provider_mapping_id']);if(is_wp_error($owner))return $owner;
            foreach(['business_id'=>'business_id','store_id'=>'store_id','product_program_id'=>'program_id'] as $ownerField=>$connectionField)if((int)$owner[$ownerField]!==$connection[$connectionField])return self::error('ownership_scope','Current ownership must match the exact reviewed shop connection.');
            $identityLine=array_intersect_key($line,array_flip(['id','listing_id','product_version_id','provider_mapping_id','quantity','currency','environment','validation_status']));
            $ownership=$this->rows($wpdb->prepare('SELECT own.id,own.business_id,own.store_id,own.product_program_id,own.product_version_id,own.provider_mapping_id,own.state,own.approved_by,own.approved_at,b.business_key,b.status AS business_status,s.store_key,s.business_id AS store_business_id,s.status AS store_status,p.program_key,p.business_id AS program_business_id,p.store_id AS program_store_id,p.status AS program_status FROM '.Tables::pod_business_mappings().' own LEFT JOIN '.Tables::businesses().' b ON b.id=own.business_id LEFT JOIN '.Tables::stores().' s ON s.id=own.store_id LEFT JOIN '.Tables::product_programs().' p ON p.id=own.product_program_id WHERE own.provider_mapping_id=%d ORDER BY own.id LIMIT 101',(int)$line['provider_mapping_id']));
            if(is_wp_error($ownership))return $ownership;
            $identities[]=['line'=>$identityLine,'mapping'=>$mapping[0],'ownership'=>$ownership];
        }
        $readiness=(new Repository())->readinessSnapshot($orderId);
        $reconciliation=(new ReconciliationReadModel())->forOrder($orderId);
        if(is_wp_error($readiness))return $readiness;
        if(($reconciliation['query_state']??'UNAVAILABLE')!=='AVAILABLE')return self::error('evidence_unavailable','Current reconciliation evidence is unavailable.',503);
        return ['order'=>$orders[0],'lines'=>$lines,'line_identity_snapshot'=>['schema'=>1,'items'=>$identities],'connection'=>$connection,'personalization'=>$personal,'plans'=>$plans,'intents'=>$intents,'renders'=>$renders,'readiness'=>$readiness,'reconciliation'=>$reconciliation,'release_hold'=>true,'external_execution_authorized'=>false,'external_execution_performed'=>false];
    }

    public function prepare(string $shop,int $orderId,int $intentId): array|WP_Error
    {
        $actor=get_current_user_id();
        $source=$this->forOrder($shop,$orderId);if(is_wp_error($source))return $source;
        $order=$source['order'];$intent=null;$plan=null;
        foreach($source['intents'] as $row)if((int)$row['id']===$intentId)$intent=$row;
        if(!is_array($intent)||$intent['state']!=='BLOCKED'||!in_array($intent['intent_type'],['PREPARE_ORDER','PREPARE_FULFILLMENT'],true))return self::error('intent','Select a stored BLOCKED order or fulfillment preparation intent.');
        foreach($source['plans'] as $row)if((int)$row['id']===(int)$intent['fulfillment_plan_id'])$plan=$row;
        if(!is_array($plan)||$plan['state']!=='APPROVED'||(int)$plan['approved_by']<1||empty($plan['approved_at'])||$order['state']!=='APPROVED'||(int)$order['approved_by']<1||empty($order['approved_at'])||$plan['environment']!==$order['environment']||$intent['environment']!==$order['environment'])return self::error('approval','Current matching human-approved order and plan are required.');
        if(empty($source['readiness']['ready'])||$source['lines']===[])return self::error('readiness','Current order readiness is blocked.');
        try{
            $payload=self::document($plan['canonical_payload']);
            if(!preg_match('/^[a-f0-9]{64}$/D',$plan['payload_hash'])||!hash_equals($plan['payload_hash'],hash('sha256',$plan['canonical_payload']))||!hash_equals($plan['payload_hash'],(string)$intent['fulfillment_plan_payload_hash'])||(int)($payload['order_id']??0)!==$orderId||($payload['provider']??null)!==$plan['provider'])throw new \InvalidArgumentException();
            foreach(['mapping'=>'provider_mapping_snapshot','print_area'=>'print_area_snapshot','shipping'=>'shipping_method_metadata','cost'=>'cost_snapshot_metadata','readiness'=>'readiness'] as $field=>$column){if(!is_array($payload[$field]??null)||Validator::canonicalJson($payload[$field])!==$plan[$column])throw new \InvalidArgumentException();}
            if(Validator::canonicalJson($payload['readiness'])!==Validator::canonicalJson($source['readiness'])||!hash_equals((string)$plan['readiness_hash'],(string)$source['readiness']['hash']))throw new \InvalidArgumentException();
            if(!is_array($payload['line_identity_snapshot']??null)||Validator::canonicalJson($payload['line_identity_snapshot'])!==Validator::canonicalJson($source['line_identity_snapshot'])||$plan['provider']!==$source['connection']['provider'])return self::error('line_snapshot','The approved plan must bind the exact current line quantities, provider material and ownership; prepare and review a new plan.');
            $lines=[];$lineVersions=[];
            foreach($source['lines'] as $line){
                if($line['validation_status']!=='VALIDATED'||$line['environment']!==$order['environment']||$line['currency']!==$order['currency']||(int)$line['provider_mapping_id']<1||(int)$line['mapping_product_version_id']!==(int)$line['product_version_id']||$line['provider']!==$plan['provider']||$line['mapping_environment']!==$order['environment']||$line['mapping_state']!=='APPROVED'||(int)$line['mapping_approved_by']<1||empty($line['mapping_approved_at']))return self::error('mapping','Each current line requires its exact approved provider mapping and environment.');
                $owner=(new BusinessScopeRepository())->assertActiveOwnershipForMapping((int)$line['provider_mapping_id']);if(is_wp_error($owner))return $owner;
                $lineVersions[(int)$line['id']]=(int)$line['product_version_id'];
                $lines[]=['line_item_id'=>(int)$line['id'],'listing_id'=>(int)$line['listing_id'],'product_version_id'=>(int)$line['product_version_id'],'provider_mapping_id'=>(int)$line['provider_mapping_id'],'quantity'=>Validator::quantity($line['quantity']),'provider_product_key'=>$line['provider_product_key'],'provider_variant_key'=>$line['provider_variant_key'],'mapping_version'=>$line['mapping_version'],'ownership_mapping_id'=>(int)$owner['id']];
            }
            $approved=(new PersonalizationCurrentness())->forPlan($orderId,(int)$order['personalization_required']===1);if(is_wp_error($approved))return $approved;
            $personalHash=hash('sha256',Validator::canonicalJson($approved));$snapshot=self::document($plan['personalization_snapshot']);
            if(Validator::canonicalJson($approved)!==Validator::canonicalJson((array)($payload['personalization']??[]))||($payload['personalization_evidence_hash']??null)!==$personalHash||($snapshot['evidence_hash']??null)!==$personalHash||Validator::canonicalJson((array)($snapshot['submissions']??[]))!==Validator::canonicalJson($approved))throw new \InvalidArgumentException();
            if(isset($payload['cost']['currency'])&&$payload['cost']['currency']!==$order['currency'])throw new \InvalidArgumentException();
            $routePayload=['order_id'=>$orderId,'fulfillment_plan_id'=>(int)$plan['id'],'fulfillment_intent_id'=>$intentId,'approved_plan_payload_hash'=>$plan['payload_hash'],'current_lines'=>$lines,'personalization'=>$approved,'print_area'=>$payload['print_area'],'intent_input'=>self::document($intent['input_payload'])];
            $prepared=FulfillmentRoutePreparation::prepare($plan['provider'],$order['environment'],$intent['intent_type'],$routePayload,$payload['shipping'],$payload['cost'],$order['currency']);if(is_wp_error($prepared))return $prepared;
            $again=$this->forOrder($shop,$orderId);if(is_wp_error($again))return $again;
            if(Validator::canonicalJson($source)!==Validator::canonicalJson($again))return self::error('changed','Stored order dependencies changed during local preparation.');
            $confirmedPersonalization=(new PersonalizationCurrentness())->forPlan($orderId,(int)$order['personalization_required']===1);if(is_wp_error($confirmedPersonalization))return $confirmedPersonalization;if($confirmedPersonalization!==$approved)return self::error('changed','Current server-selected input changed during held preparation.');
            if($actor<1||!is_user_logged_in()||get_current_user_id()!==$actor)return self::error('actor','The held preparation actor changed.',403);foreach(['manage_digiforge_orders','manage_digiforge_pod'] as $cap){$access=ShopAccess::authorize($shop,$cap,$actor);if(is_wp_error($access))return $access;}if(!is_user_logged_in()||get_current_user_id()!==$actor||!current_user_can('manage_digiforge_orders')||!current_user_can('manage_digiforge_pod'))return self::error('actor','Current held preparation admission changed.',403);if(!is_user_logged_in()||get_current_user_id()!==$actor)return self::error('actor','The held preparation actor changed during final admission.',403);
            return ['state'=>'HELD_FULFILLMENT_PREPARED','shop_key'=>$shop,'order_id'=>$orderId,'fulfillment_plan_id'=>(int)$plan['id'],'fulfillment_intent_id'=>$intentId,'plan_payload_hash'=>$plan['payload_hash'],'current_evidence_hash'=>Validator::hash($source),'preparation'=>$prepared,'render_artifact_recheck_required'=>true,'production_approval_required'=>true,'release_hold'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];
        }catch(\Throwable){return self::error('snapshot','Stored plan snapshots and current typed personalization must agree exactly.');}
    }

    private function rows(string $sql): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);
        return !is_array($rows)||$wpdb->last_error!==''||count($rows)>100?self::error('evidence_unavailable','Bounded current order evidence could not be read completely.',503):$rows;
    }

    /** Resolve one current independent local connection review; never decrypt credentials. */
    private function connection(string $shop,string $environment): array|WP_Error
    {
        global $wpdb;
        $rows=$this->rows($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE BINARY provider=BINARY 'printify' AND BINARY environment=BINARY %s AND BINARY status=BINARY 'CONFIGURED' AND CASE WHEN JSON_VALID(config) THEN BINARY JSON_UNQUOTE(JSON_EXTRACT(config,'$.shop_key'))=BINARY %s ELSE false END LIMIT 2",$environment,$shop));
        if(is_wp_error($rows))return $rows;
        if(count($rows)!==1)return self::error('connection','One exact reviewed shop/provider connection is required.');
        return (new ScopedProviderConnection())->verified($shop,(int)$rows[0]['id'],'printify.connection.read');
    }

    private static function document(string $json): array
    {
        if(strlen($json)>65536)throw new \InvalidArgumentException();
        $value=json_decode($json,true,32,JSON_THROW_ON_ERROR);$value=Validator::structured($value);
        if(Validator::canonicalJson($value)!==$json)throw new \InvalidArgumentException();
        return $value;
    }

    private static function error(string $code,string $message,int $status=409): WP_Error
    {
        return new WP_Error('held_fulfillment_'.$code,$message,['status'=>$status,'release_hold'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false]);
    }
}
