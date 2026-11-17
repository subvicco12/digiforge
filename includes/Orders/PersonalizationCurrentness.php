<?php
declare(strict_types=1);
namespace DigiForge\Orders;

use DigiForge\Database\Tables;
use DigiForge\POD\PersonalizationPayloadContract;
use DigiForge\POD\CustomerPhotoSelection;
use DigiForge\Listings\EtsyStoredShopScope;
use WP_Error;

/** Read-only currentness, never selection or supersession of immutable buyer approvals. */
final class PersonalizationCurrentness
{
    public function __construct(private ?PersonalizationReadContext $context=null) {}
    /** Validate existing input even when the order's personalization-required flag is false. */
    public function forOrder(int $orderId,bool $requireEveryLine=true): array|WP_Error
    {
        if($this->context!==null)return $this->context->forOrder($orderId,$requireEveryLine);
        if($orderId<1)return self::error('identity','An exact stored order identity is required.');
        global $wpdb;
        $photoScope=EtsyStoredShopScope::orderPredicate('personalized_pod','o');
        $lines=$this->rows($wpdb->prepare('SELECT li.id,li.product_version_id,li.provider_mapping_id,li.environment,li.validation_status,o.channel,o.environment AS order_environment,CASE WHEN '.$photoScope.' THEN 1 ELSE 0 END AS photo_shop_scope,m.id AS mapping_id,m.product_version_id AS mapping_product_version_id,m.environment AS mapping_environment FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::orders().' o ON o.id=li.order_id LEFT JOIN '.Tables::pod_mappings().' m ON m.id=li.provider_mapping_id WHERE li.order_id=%d ORDER BY li.id LIMIT 101',$orderId));
        if(is_wp_error($lines))return $lines;
        if($lines===[])return self::error('missing','Stored order lines are required.');
        $mappingCounts=[];foreach($lines as $line){$mapping=(int)$line['provider_mapping_id'];if($mapping>0)$mappingCounts[$mapping]=($mappingCounts[$mapping]??0)+1;}
        // Partial guard only: an old RR snapshot can still report legacy NULL before a first
        // managed selection commits. That frontier remains unqualified; this is no general fresh-read guarantee.
        $current=[];$selections=[];
        foreach($lines as $line){
            $selection=null;if((int)$line['photo_shop_scope']===1){$selection=(new CustomerPhotoSelection())->resolveForOrder('personalized_pod',$orderId,(int)$line['id']);if(is_wp_error($selection))return $selection;$selections[(int)$line['id']]=$selection;if($selection!==null){$mode=$this->rows('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit');if(is_wp_error($mode))return $mode;if(count($mode)!==1||(string)$mode[0]['active']!=='0'||(string)$mode[0]['autocommit']!=='1')return self::error('selection_current_read_required','Managed photo consumption requires an authoritative fresh read outside a caller transaction.');}}
            // Do not filter by hash, schema existence or approval: newer unresolved/orphan input must remain visible.
            if($selection!==null&&(!is_int($selection['submission_id']??null)||$selection['submission_id']<1))return self::error('selection_identity','The authoritative canonical submission identity is invalid.');
            $selectedWhere=$selection===null?'':$wpdb->prepare(' AND ps.id=%d',$selection['submission_id']);
            $rows=$this->rows($wpdb->prepare('SELECT ps.id,ps.personalization_schema_id,ps.canonical_payload,ps.payload_hash,ps.review_status,ps.reviewed_by,ps.reviewed_at,ps.environment,s.id AS schema_id,s.product_version_id AS schema_product_version_id,s.field_definitions,s.state AS schema_state,s.approved_by AS schema_approved_by,s.approved_at AS schema_approved_at FROM '.Tables::personalization_submissions().' ps LEFT JOIN '.Tables::personalization_schemas().' s ON s.id=ps.personalization_schema_id WHERE ps.order_line_item_id=%d'.$selectedWhere.' ORDER BY ps.id LIMIT 101',(int)$line['id']));
            if(is_wp_error($rows))return $rows;
            $entry=self::validateLine($orderId,$line,$rows,$selection,$mappingCounts,$requireEveryLine);if(is_wp_error($entry))return $entry;if($entry!==null)$current[]=$entry;
        }
        foreach($selections as $line=>$expected){$confirmed=(new CustomerPhotoSelection())->resolveForOrder('personalized_pod',$orderId,$line,$expected['revision']??null);if(is_wp_error($confirmed))return $confirmed;if($confirmed!==$expected)return self::error('selection_changed','Current selected-photo evidence changed during consumption.');}
        return $current;
    }

    /** Pure shared validation, not a source/admission grant. The context supplies its own current SQL material. */
    public static function projectLegacyMaterial(array $material,bool $requireEveryLine):array|WP_Error
    {
        $selections=[];foreach($material['lines']as $line)$selections[(int)$line['id']]=null;
        return self::projectRetainedMaterial($material,$selections,$requireEveryLine);
    }

    /** Pure projection only: selection provenance/authority is retained by the issuing context. */
    public static function projectRetainedMaterial(array $material,array $selections,bool $requireEveryLine):array|WP_Error
    {
        $expected=array_map(static fn(array $line):int=>(int)$line['id'],$material['lines']);$keys=array_keys($selections);sort($expected);sort($keys);
        if($expected!==$keys)return self::error('selection_identity','Every exact material line requires its captured authoritative selection or absence.');
        $current=[];$counts=[];$order=$material['order'];foreach($material['lines']as $line){$id=(int)$line['provider_mapping_id'];if($id>0)$counts[$id]=($counts[$id]??0)+1;}
        foreach($material['lines']as $raw){
            $mapping=$material['graph']['mappings'][(int)$raw['provider_mapping_id']][0]??[];
            $selection=$selections[(int)$raw['id']];
            if($selection!==null){
                $expectedMapping=$selection['snapshot']['provider_mapping_sha256']??null;
                try{$actualMapping=hash('sha256',Validator::canonicalJson($mapping));}catch(\Throwable){return self::error('selection_identity','The retained full mapping source is unavailable.');}
                if(!is_string($expectedMapping)||preg_match('/^[a-f0-9]{64}$/D',$expectedMapping)!==1||!hash_equals($expectedMapping,$actualMapping))return self::error('selection_identity','The retained producer snapshot must bind the exact full current mapping.');
            }
            $line=array_merge($raw,['channel'=>$order['channel'],'order_environment'=>$order['environment'],'mapping_id'=>$mapping['id']??null,'mapping_product_version_id'=>$mapping['product_version_id']??null,'mapping_environment'=>$mapping['environment']??null]);$rows=[];
            foreach($material['graph']['submissions'][(int)$raw['id']]as $ps){$schema=$material['graph']['schemas'][(int)$ps['personalization_schema_id']][0]??[];$rows[]=array_merge($ps,['schema_id'=>$schema['id']??null,'schema_product_version_id'=>$schema['product_version_id']??null,'field_definitions'=>$schema['field_definitions']??null,'schema_state'=>$schema['state']??null,'schema_approved_by'=>$schema['approved_by']??null,'schema_approved_at'=>$schema['approved_at']??null]);}
            $entry=self::validateLine((int)$order['id'],$line,$rows,$selections[(int)$raw['id']],$counts,$requireEveryLine);if(is_wp_error($entry))return $entry;if($entry!==null)$current[]=$entry;
        }return $current;
    }
    private static function validateLine(int $orderId,array $line,array $rows,?array $selection,array $mappingCounts,bool $requireEveryLine):array|WP_Error|null
    {
    if($rows===[]){if($requireEveryLine)return self::error('missing','Every required line needs a current reviewed buyer input.');return null;}
    if($line['channel']!=='etsy'||$line['validation_status']!=='VALIDATED'||$line['environment']!==$line['order_environment'])return self::error('line','Exact validated Etsy order-line scope is required.');
    $mapping=(int)$line['provider_mapping_id'];
    if($mapping>0&&(($mappingCounts[$mapping]??0)!==1||(int)$line['mapping_id']!==$mapping||(int)$line['mapping_product_version_id']!==(int)$line['product_version_id']||$line['mapping_environment']!==$line['environment']))return self::error('ambiguous','A buyer render requires one exact mapped order line.');
    if($selection===null){
        $approved=array_values(array_filter($rows,static fn(array $row):bool=>$row['review_status']==='APPROVED'));$latest=$rows[count($rows)-1];
        if(count($approved)!==1||$latest['review_status']!=='APPROVED'||(int)$latest['id']!==(int)$approved[0]['id'])return self::error('selection_required','Current buyer input is unresolved or ambiguous; an explicit correction selection is required.');$row=$approved[0];
    }else{
        if(($selection['shop_key']??null)!=='personalized_pod'||($selection['review_state']??null)!=='APPROVED'||!is_int($selection['revision']??null)||$selection['revision']<1||!is_string($selection['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$selection['sha256'])!==1||!is_int($selection['submission_id']??null)||!is_array($selection['snapshot']??null))return self::error('selection_identity','The authoritative selected-photo identity is invalid.');
        $matches=array_values(array_filter($rows,static fn(array $row):bool=>(int)$row['id']===$selection['submission_id']));if(count($matches)!==1||$matches[0]['review_status']!=='APPROVED')return self::error('selection_identity','The selected canonical submission is unavailable or no longer approved.');$row=$matches[0];$selected=$selection['snapshot'];
        if(($selected['order_id']??null)!==$orderId||($selected['line_item_id']??null)!==(int)$line['id']||($selected['product_version_id']??null)!==(int)$line['product_version_id']||($selected['provider_mapping_id']??null)!==$mapping||($selected['schema_id']??null)!==(int)$row['personalization_schema_id']||($selected['payload_sha256']??null)!==$row['payload_hash']||($selected['schema_sha256']??null)!==hash('sha256',(string)$row['field_definitions'])||!is_array($selected['payload']??null)||Validator::canonicalJson($selected['payload'])!==$row['canonical_payload'])return self::error('selection_identity','Exact selected order, line, version, mapping, typed schema and canonical payload must still match.');
    }
    if((int)$row['reviewed_by']<1||empty($row['reviewed_at'])||$row['environment']!==$line['environment']||(int)$row['schema_id']!==(int)$row['personalization_schema_id']||(int)$row['schema_product_version_id']!==(int)$line['product_version_id']||$row['schema_state']!=='APPROVED'||(int)$row['schema_approved_by']<1||empty($row['schema_approved_at']))return self::error('schema','The exact current human-approved typed schema and input are required.');
    try{
        if(strlen((string)$row['canonical_payload'])>65536||strlen((string)$row['field_definitions'])>65536||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['payload_hash'])||!hash_equals((string)$row['payload_hash'],hash('sha256',(string)$row['canonical_payload'])))throw new \InvalidArgumentException();
        $payload=json_decode((string)$row['canonical_payload'],true,32,JSON_THROW_ON_ERROR);$fields=json_decode((string)$row['field_definitions'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||!is_array($fields)||Validator::canonicalJson($payload)!==$row['canonical_payload'])throw new \InvalidArgumentException();
        PersonalizationPayloadContract::validate($fields,$payload);
    }catch(\Throwable){return self::error('payload','Current buyer values must match their exact canonical hash and typed schema.');}
    $entry=['order_line_item_id'=>(int)$line['id'],'product_version_id'=>(int)$line['product_version_id'],'provider_mapping_id'=>$mapping,'submission_id'=>(int)$row['id'],'personalization_schema_id'=>(int)$row['personalization_schema_id'],'payload_hash'=>$row['payload_hash'],'schema_sha256'=>hash('sha256',(string)$row['field_definitions']),'reviewed_by'=>(int)$row['reviewed_by'],'reviewed_at'=>$row['reviewed_at'],'schema_approved_by'=>(int)$row['schema_approved_by'],'schema_approved_at'=>$row['schema_approved_at']];if($selection!==null)$entry+=['selection_sha256'=>$selection['sha256'],'selection_revision'=>$selection['revision']];return $entry;
    }

    /** Canonical plan/held projection from server-resolved current input, never caller selection JSON. */
    public function forPlan(int $orderId,bool $requireEveryLine=true): array|WP_Error
    {
        $current=$this->forOrder($orderId,$requireEveryLine);if(is_wp_error($current))return $current;$snapshot=[];
        foreach($current as $row){
            $entry=['submission_id'=>(int)$row['submission_id'],'order_line_item_id'=>(int)$row['order_line_item_id'],'personalization_schema_id'=>(int)$row['personalization_schema_id'],'payload_hash'=>$row['payload_hash'],'review_status'=>'APPROVED','reviewed_by'=>(int)$row['reviewed_by'],'reviewed_at'=>(string)$row['reviewed_at']];
            // Optional fields are emitted only by an authoritative managed-input branch.
            foreach(['selection_sha256','selection_revision'] as $key)if(array_key_exists($key,$row))$entry[$key]=$row[$key];
            $snapshot[]=$entry;
        }
        return $snapshot;
    }

    public function forRender(int $orderId,int $mappingId,string $payloadHash,?array $render=null): array|WP_Error
    {
        if($mappingId<1||!preg_match('/^[a-f0-9]{64}$/D',$payloadHash))return self::error('identity','Exact render mapping and buyer-input hash are required.');
        $selected=(new CustomerPhotoSelection())->forRender($render??['order_id'=>$orderId,'provider_mapping_id'=>$mappingId,'personalization_evidence_hash'=>$payloadHash]);if(is_wp_error($selected))return $selected;
        $current=$this->forOrder($orderId,false);if(is_wp_error($current))return $current;
        $matches=array_values(array_filter($current,static fn(array $row):bool=>$row['provider_mapping_id']===$mappingId));
        if(count($matches)!==1||!hash_equals($matches[0]['payload_hash'],$payloadHash))return self::error('render_stale','The render does not bind one uniquely current approved buyer input.');
        if($selected!==null){
            if(($matches[0]['selection_sha256']??null)!==$selected['sha256']||($matches[0]['selection_revision']??null)!==$selected['revision']||$render===null||(int)($render['id']??0)<1)return self::error('render_stale','An exact stored render and current selection epoch are required.');
            global $wpdb;$stored=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d AND order_id=%d AND provider_mapping_id=%d LIMIT 2',(int)$render['id'],$orderId,$mappingId));if(is_wp_error($stored))return $stored;if(count($stored)!==1||$stored[0]['personalization_evidence_hash']!==$payloadHash||$stored[0]['evidence_hash']!==($render['evidence_hash']??null))return self::error('render_stale','Stored render identity changed.');
            $certified=(new \DigiForge\POD\RenderArtifactVerifier())->assertCertified($stored[0]);if(is_wp_error($certified))return $certified;if(($certified['deterministic_personalization_verified']??false)!==true)return self::error('render_stale','Current managed artifacts require the actual owner epoch verification.');
            $confirmed=(new CustomerPhotoSelection())->forRender($stored[0]);if(is_wp_error($confirmed))return $confirmed;if($confirmed===null||$confirmed['sha256']!==$selected['sha256']||$confirmed['revision']!==$selected['revision'])return self::error('render_stale','The managed render selection changed during owner verification.');
        }
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
