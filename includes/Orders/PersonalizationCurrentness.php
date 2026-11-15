<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\POD\PersonalizationPayloadContract;
use WP_Error;

/** Read-only currentness, never selection or supersession of immutable buyer approvals. */
final class PersonalizationCurrentness
{
    /** Validate existing input even when the order's personalization-required flag is false. */
    public function forOrder(int $orderId,bool $requireEveryLine=true): array|WP_Error
    {
        if($orderId<1)return self::error('identity','An exact stored order identity is required.');
        global $wpdb;
        $lines=$this->rows($wpdb->prepare('SELECT li.id,li.product_version_id,li.provider_mapping_id,li.environment,li.validation_status,o.channel,o.environment AS order_environment,m.id AS mapping_id,m.product_version_id AS mapping_product_version_id,m.environment AS mapping_environment FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::orders().' o ON o.id=li.order_id LEFT JOIN '.Tables::pod_mappings().' m ON m.id=li.provider_mapping_id WHERE li.order_id=%d ORDER BY li.id LIMIT 101',$orderId));
        if(is_wp_error($lines))return $lines;
        if($lines===[])return self::error('missing','Stored order lines are required.');
        $mappingCounts=[];foreach($lines as $line){$mapping=(int)$line['provider_mapping_id'];if($mapping>0)$mappingCounts[$mapping]=($mappingCounts[$mapping]??0)+1;}
        $current=[];
        foreach($lines as $line){
            // Do not filter by hash, schema existence or approval: newer unresolved/orphan input must remain visible.
            $rows=$this->rows($wpdb->prepare('SELECT ps.id,ps.personalization_schema_id,ps.canonical_payload,ps.payload_hash,ps.review_status,ps.reviewed_by,ps.reviewed_at,ps.environment,s.id AS schema_id,s.product_version_id AS schema_product_version_id,s.field_definitions,s.state AS schema_state,s.approved_by AS schema_approved_by,s.approved_at AS schema_approved_at FROM '.Tables::personalization_submissions().' ps LEFT JOIN '.Tables::personalization_schemas().' s ON s.id=ps.personalization_schema_id WHERE ps.order_line_item_id=%d ORDER BY ps.id LIMIT 101',(int)$line['id']));
            if(is_wp_error($rows))return $rows;
            if($rows===[]){if($requireEveryLine)return self::error('missing','Every required line needs a current reviewed buyer input.');continue;}
            if($line['channel']!=='etsy'||$line['validation_status']!=='VALIDATED'||$line['environment']!==$line['order_environment'])return self::error('line','Exact validated Etsy order-line scope is required.');
            $mapping=(int)$line['provider_mapping_id'];
            if($mapping>0&&(($mappingCounts[$mapping]??0)!==1||(int)$line['mapping_id']!==$mapping||(int)$line['mapping_product_version_id']!==(int)$line['product_version_id']||$line['mapping_environment']!==$line['environment']))return self::error('ambiguous','A buyer render requires one exact mapped order line.');
            $approved=array_values(array_filter($rows,static fn(array $row):bool=>$row['review_status']==='APPROVED'));
            $latest=$rows[count($rows)-1];
            if(count($approved)!==1||$latest['review_status']!=='APPROVED'||(int)$latest['id']!==(int)$approved[0]['id'])return self::error('selection_required','Current buyer input is unresolved or ambiguous; an explicit correction selection is required.');
            $row=$approved[0];
            if((int)$row['reviewed_by']<1||empty($row['reviewed_at'])||$row['environment']!==$line['environment']||(int)$row['schema_id']!==(int)$row['personalization_schema_id']||(int)$row['schema_product_version_id']!==(int)$line['product_version_id']||$row['schema_state']!=='APPROVED'||(int)$row['schema_approved_by']<1||empty($row['schema_approved_at']))return self::error('schema','The exact current human-approved typed schema and input are required.');
            try{
                if(strlen((string)$row['canonical_payload'])>65536||strlen((string)$row['field_definitions'])>65536||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['payload_hash'])||!hash_equals((string)$row['payload_hash'],hash('sha256',(string)$row['canonical_payload'])))throw new \InvalidArgumentException();
                $payload=json_decode((string)$row['canonical_payload'],true,32,JSON_THROW_ON_ERROR);$fields=json_decode((string)$row['field_definitions'],true,32,JSON_THROW_ON_ERROR);
                if(!is_array($payload)||!is_array($fields)||Validator::canonicalJson($payload)!==$row['canonical_payload'])throw new \InvalidArgumentException();
                PersonalizationPayloadContract::validate($fields,$payload);
            }catch(\Throwable){return self::error('payload','Current buyer values must match their exact canonical hash and typed schema.');}
            $current[]=['order_line_item_id'=>(int)$line['id'],'product_version_id'=>(int)$line['product_version_id'],'provider_mapping_id'=>$mapping,'submission_id'=>(int)$row['id'],'personalization_schema_id'=>(int)$row['personalization_schema_id'],'payload_hash'=>$row['payload_hash'],'schema_sha256'=>hash('sha256',(string)$row['field_definitions']),'reviewed_by'=>(int)$row['reviewed_by'],'reviewed_at'=>$row['reviewed_at'],'schema_approved_by'=>(int)$row['schema_approved_by'],'schema_approved_at'=>$row['schema_approved_at']];
        }
        return $current;
    }

    public function forRender(int $orderId,int $mappingId,string $payloadHash): array|WP_Error
    {
        if($mappingId<1||!preg_match('/^[a-f0-9]{64}$/D',$payloadHash))return self::error('identity','Exact render mapping and buyer-input hash are required.');
        $current=$this->forOrder($orderId,false);if(is_wp_error($current))return $current;
        $matches=array_values(array_filter($current,static fn(array $row):bool=>$row['provider_mapping_id']===$mappingId));
        if(count($matches)!==1||!hash_equals($matches[0]['payload_hash'],$payloadHash))return self::error('render_stale','The render does not bind one uniquely current approved buyer input.');
        return $matches[0];
    }

    private function rows(string $sql): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);
        return !is_array($rows)||$wpdb->last_error!==''||count($rows)>100?self::error('evidence_unavailable','Complete current buyer-input evidence could not be read.',503):$rows;
    }

    private static function error(string $code,string $message,int $status=409): WP_Error
    {
        return new WP_Error('personalization_currentness_'.$code,$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false]);
    }
}
