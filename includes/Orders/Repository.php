<?php

declare(strict_types=1);

namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function createOrder(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $channel=Validator::channel((string)($input['channel']??'etsy'));$environment=Validator::environment((string)($input['environment']??''));$currency=Validator::currency((string)($input['currency']??'USD'));$subtotal=Validator::amount($input['subtotal_amount']??0);$shipping=Validator::amount($input['shipping_amount']??0);$tax=Validator::amount($input['tax_amount']??0);$total=Validator::amount($input['total_amount']??($subtotal+$shipping+$tax));Validator::structured((array)($input['metadata']??[]));
        } catch (\InvalidArgumentException $e) { return $this->error('validation',$e->getMessage()); }
        return $this->insert(Tables::orders(),$key,['channel'=>$channel,'environment'=>$environment,'external_order_reference'=>sanitize_text_field((string)($input['external_order_reference']??'')),'shop_reference'=>sanitize_text_field((string)($input['shop_reference']??'')),'buyer_reference'=>sanitize_text_field((string)($input['buyer_reference']??'')),'currency'=>$currency,'subtotal_amount'=>$subtotal,'shipping_amount'=>$shipping,'tax_amount'=>$tax,'total_amount'=>$total,'personalization_required'=>!empty($input['personalization_required'])?1:0,'state'=>'RECEIVED','approved_by'=>0,'approved_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'order');
    }

    public function findByExternalReference(string $externalReference,string $shopReference): ?array
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE external_order_reference=%s AND shop_reference=%s ORDER BY id DESC LIMIT 1',$externalReference,$shopReference),ARRAY_A);
        return is_array($row)?$row:null;
    }

    public function addLineItem(array $input, ?string $key = null): array|WP_Error
    {
        $order=$this->find(Tables::orders(),absint($input['order_id']??0));$version=$this->find(Tables::product_versions(),absint($input['product_version_id']??0));if(!is_array($order)||!is_array($version))return $this->error('invalid_parent','Valid order and product version are required.');
        $listingId=absint($input['listing_id']??0);if($listingId>0){$listing=$this->find(Tables::listings(),$listingId);if(!is_array($listing)||(int)$listing['product_version_id']!==(int)$version['id']||(string)$listing['environment']!==(string)$order['environment'])return $this->error('invalid_relationship','Listing, product version and order environment must match.',409);}
        $mappingId=absint($input['provider_mapping_id']??0);if($mappingId>0){$mapping=$this->find(Tables::pod_mappings(),$mappingId);if(!is_array($mapping)||(int)$mapping['product_version_id']!==(int)$version['id']||(string)$mapping['environment']!==(string)$order['environment']||(string)($mapping['state']??'')!=='APPROVED')return $this->error('invalid_relationship','Approved POD mapping must match product version and environment.',409);}
        try{$quantity=Validator::quantity($input['quantity']??1);$currency=Validator::currency((string)($input['currency']??$order['currency']));$price=Validator::amount($input['unit_price_amount']??0);$personalization=Validator::structured((array)($input['personalization_payload']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        return $this->insert(Tables::order_line_items(),$key,['order_id'=>(int)$order['id'],'listing_id'=>$listingId,'product_version_id'=>(int)$version['id'],'provider_mapping_id'=>$mappingId,'quantity'=>$quantity,'unit_price_amount'=>$price,'currency'=>$currency,'personalization_payload'=>Validator::canonicalJson($personalization),'environment'=>(string)$order['environment'],'validation_status'=>'VALIDATED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'order_line_item');
    }

    public function createPersonalization(array $input, ?string $key = null): array|WP_Error
    {
        $line=$this->find(Tables::order_line_items(),absint($input['order_line_item_id']??0));$schema=$this->find(Tables::personalization_schemas(),absint($input['personalization_schema_id']??0));if(!is_array($line)||!is_array($schema)||(int)$schema['product_version_id']!==(int)$line['product_version_id']||(string)$schema['state']!=='APPROVED')return $this->error('invalid_relationship','Approved personalization schema must match the line item product.',409);
        try{$payload=Validator::structured((array)($input['payload']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}$canonical=Validator::canonicalJson($payload);
        return $this->insert(Tables::personalization_submissions(),$key,['order_line_item_id'=>(int)$line['id'],'personalization_schema_id'=>(int)$schema['id'],'canonical_payload'=>$canonical,'payload_hash'=>hash('sha256',$canonical),'review_status'=>'UNREVIEWED','reviewed_by'=>0,'reviewed_at'=>null,'environment'=>(string)$line['environment'],'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'personalization_submission');
    }

    public function reviewPersonalization(int $id,string $status): array|WP_Error
    {
        $reviewerId=get_current_user_id();if($reviewerId<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);$row=$this->find(Tables::personalization_submissions(),$id);if(!is_array($row))return $this->error('not_found','Personalization submission not found.',404);$status=strtoupper(sanitize_key($status));if(!in_array($status,['APPROVED','REJECTED'],true))return $this->error('validation','Personalization review status must be APPROVED or REJECTED.');if((string)$row['review_status']===$status)return $row+['idempotent_transition'=>true];if((string)$row['review_status']!=='UNREVIEWED')return $this->error('invalid_transition','Reviewed personalization cannot be overwritten.',409);
        $now=$this->now();global $wpdb;$ok=$wpdb->update(Tables::personalization_submissions(),['review_status'=>$status,'reviewed_by'=>$reviewerId,'reviewed_at'=>$now,'updated_at'=>$now],['id'=>$id,'review_status'=>'UNREVIEWED']);if($ok!==1)return $this->error('transition_conflict','Personalization review changed concurrently or update failed.',409);Logger::audit('personalization_reviewed',['status'=>$status],'personalization_submission',(string)$id);return $this->find(Tables::personalization_submissions(),$id)?:[];
    }

    public function createPlan(array $input, ?string $key = null): array|WP_Error
    {
        $order=$this->find(Tables::orders(),absint($input['order_id']??0));if(!is_array($order))return $this->error('invalid_parent','Valid order_id is required.');
        try{$provider=sanitize_key((string)($input['provider']??''));$mapping=Validator::structured((array)($input['provider_mapping_snapshot']??[]));$print=Validator::structured((array)($input['print_area_snapshot']??[]));$personalization=Validator::structured((array)($input['personalization_snapshot']??[]));$shipping=Validator::structured((array)($input['shipping_method_metadata']??[]));$cost=Validator::structured((array)($input['cost_snapshot_metadata']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        $readiness=$this->readiness((int)$order['id']);if(is_wp_error($readiness))return $readiness;if(empty($readiness['ready']))return $this->error('not_ready','Order must pass current readiness before a fulfillment plan can be created.',409);
        $payload=['order_id'=>(int)$order['id'],'provider'=>$provider,'mapping'=>$mapping,'print_area'=>$print,'personalization'=>$personalization,'shipping'=>$shipping,'cost'=>$cost,'readiness'=>$readiness];$canonical=Validator::canonicalJson($payload);
        return $this->insert(Tables::fulfillment_plans(),$key,['order_id'=>(int)$order['id'],'plan_version'=>sanitize_text_field((string)($input['plan_version']??'v1')),'provider'=>$provider,'provider_mapping_snapshot'=>Validator::canonicalJson($mapping),'print_area_snapshot'=>Validator::canonicalJson($print),'personalization_snapshot'=>Validator::canonicalJson($personalization),'shipping_method_metadata'=>Validator::canonicalJson($shipping),'cost_snapshot_metadata'=>Validator::canonicalJson($cost),'canonical_payload'=>$canonical,'payload_hash'=>hash('sha256',$canonical),'readiness'=>Validator::canonicalJson($readiness),'readiness_hash'=>(string)$readiness['hash'],'state'=>'DRAFT','approved_by'=>0,'approved_at'=>null,'environment'=>(string)$order['environment'],'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'fulfillment_plan');
    }

    public function createIntent(array $input, ?string $key = null): array|WP_Error
    {
        $order=$this->find(Tables::orders(),absint($input['order_id']??0));if(!is_array($order))return $this->error('invalid_parent','Valid order_id is required.');$type=strtoupper((string)($input['intent_type']??''));if(!in_array($type,Lifecycle::INTENT_TYPES,true))return $this->error('validation','Invalid fulfillment intent type.');
        $planId=absint($input['fulfillment_plan_id']??0);$plan=$this->find(Tables::fulfillment_plans(),$planId);if(!is_array($plan)||(int)$plan['order_id']!==(int)$order['id']||(string)$plan['environment']!==(string)$order['environment']||(string)$plan['state']!=='APPROVED')return $this->error('invalid_relationship','Intent requires an approved fulfillment plan for the same order and environment.',409);$current=$this->readiness((int)$order['id']);if(is_wp_error($current)||empty($current['ready'])||!hash_equals((string)$plan['readiness_hash'],(string)($current['hash']??'')))return $this->error('stale_readiness','Fulfillment plan readiness is stale; rebuild and reapprove the plan.',409);
        try{$payload=Validator::structured((array)($input['input_payload']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        return $this->insert(Tables::fulfillment_intents(),$key,['order_id'=>(int)$order['id'],'fulfillment_plan_id'=>$planId,'environment'=>(string)$order['environment'],'intent_type'=>$type,'input_payload'=>Validator::canonicalJson($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'fulfillment_intent');
    }

    public function transition(string $entity,int $id,string $to): array|WP_Error
    {
        $table=match($entity){'order'=>Tables::orders(),'plan'=>Tables::fulfillment_plans(),'intent'=>Tables::fulfillment_intents(),default=>''};$reviewerId=get_current_user_id();if($table==='')return $this->error('validation','Unknown lifecycle entity.');$row=$this->find($table,$id);$to=strtoupper(sanitize_key($to));if(!is_array($row))return $this->error('not_found','Order record not found.',404);if((string)$row['state']===$to)return $row+['idempotent_transition'=>true];if(!Lifecycle::can($entity,(string)$row['state'],$to))return $this->error('invalid_transition','Lifecycle transition is not permitted.',409);if(in_array($to,['APPROVED','APPROVED_INTENT'],true)&&$reviewerId<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);
        if($entity==='plan'&&$to==='APPROVED'){$current=$this->readiness((int)$row['order_id']);if(is_wp_error($current)||empty($current['ready'])||!hash_equals((string)$row['readiness_hash'],(string)($current['hash']??'')))return $this->error('stale_readiness','Fulfillment plan readiness is stale; rebuild the plan before approval.',409);}
        $now=$this->now();global $wpdb;$data=['state'=>$to,'updated_at'=>$now];if(($entity==='order'||$entity==='plan')&&$to==='APPROVED'){$data['approved_by']=$reviewerId;$data['approved_at']=$now;}if($wpdb->update($table,$data,['id'=>$id,'state'=>(string)$row['state']])!==1)return $this->error('transition_conflict','State changed concurrently or update failed.',409);Logger::audit('order_state_changed',['from'=>$row['state'],'to'=>$to],$entity,(string)$id);return $this->find($table,$id)?:[];
    }

    public function readiness(int $orderId): array|WP_Error
    {
        $order=$this->find(Tables::orders(),$orderId);if(!is_array($order))return $this->error('not_found','Order not found.',404);global $wpdb;$lineCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d',$orderId));$invalidLines=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." WHERE order_id=%d AND validation_status<>'VALIDATED'",$orderId));$personalizationMissing=0;
        if((int)$order['personalization_required']===1){$personalizationMissing=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::order_line_items()." li WHERE li.order_id=%d AND NOT EXISTS (SELECT 1 FROM ".Tables::personalization_submissions()." ps WHERE ps.order_line_item_id=li.id AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL)",$orderId));}
        $checks=['order_approved'=>(string)$order['state']==='APPROVED','line_items_present'=>$lineCount>0,'line_items_valid'=>$invalidLines===0,'personalization_reviewed'=>$personalizationMissing===0,'human_approval'=>(int)$order['approved_by']>0&&!empty($order['approved_at'])];$payload=['order_id'=>$orderId,'ready'=>!in_array(false,$checks,true),'checks'=>$checks];$payload['hash']=hash('sha256',Validator::canonicalJson($payload));return $payload;
    }

    public function list(string $entity,int $page=1,int $perPage=20): array
    {
        $tables=['orders'=>Tables::orders(),'items'=>Tables::order_line_items(),'personalizations'=>Tables::personalization_submissions(),'plans'=>Tables::fulfillment_plans(),'intents'=>Tables::fulfillment_intents(),'reviews'=>Tables::fulfillment_readiness_reviews()];$table=$tables[$entity]??'';if($table==='')return['items'=>[],'pagination'=>['total_items'=>0,'total_pages'=>0]];global $wpdb;$page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;$items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",$perPage,$offset),ARRAY_A)?:[];$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");return['items'=>$items,'pagination'=>['total_items'=>$total,'total_pages'=>(int)ceil($total/max(1,$perPage))]];
    }

    private function insert(string $table,?string $key,array $data,string $objectType): array|WP_Error
    {
        global $wpdb;$key=$key===null?null:sanitize_text_field($key);if($key==='')$key=null;if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key is too long.');if($key!==null){$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);if(is_array($existing)){if(!$this->replayCompatible($existing,$data))return $this->error('idempotency_payload_conflict','Idempotency key was already used with different immutable order data.',409);return $existing+['idempotent_replay'=>true];}$data['idempotency_key']=$key;}
        if($wpdb->insert($table,$data)!==1){if($key!==null){$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);if(is_array($existing)&&$this->replayCompatible($existing,$data))return $existing+['idempotent_replay'=>true];}return $this->error('database_error','Unable to persist order record.',500);}$id=(int)$wpdb->insert_id;Logger::audit($objectType.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$objectType,(string)$id);return $this->find($table,$id)?:[];
    }

    private function replayCompatible(array $existing,array $incoming): bool
    {
        $ignore=['id','idempotency_key','state','created_at','updated_at','created_by','approved_by','approved_at','review_status','reviewed_by','reviewed_at'];foreach($incoming as $field=>$value){if(in_array($field,$ignore,true)||!array_key_exists($field,$existing))continue;$left=$existing[$field];if(is_numeric($value)&&is_numeric($left)){if((string)(float)$left!==(string)(float)$value)return false;}elseif((string)$left!==(string)$value)return false;}return true;
    }

    private function find(string $table,int $id): ?array
    {
        if($id<1)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A);return is_array($row)?$row:null;
    }
    private function now(): string{return current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400): WP_Error{return new WP_Error($code,__($message,'digiforge'),['status'=>$status]);}
}
