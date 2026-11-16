<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Orders\HeldFulfillmentPreparation;
use DigiForge\Orders\PersonalizationCurrentness;
use DigiForge\Orders\Validator;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Scoped stored metadata only. No artifact bytes, preview URL or certification are issued. */
final class OrderArtifactInspection
{
    public function inspect(string $shop,int $orderId,int $renderId,?int $packageId=null): array|WP_Error
    {
        $actor=get_current_user_id();
        if($orderId<1||$orderId>2147483647||$renderId<1||$renderId>2147483647||($packageId!==null&&($packageId<1||$packageId>2147483647)))return self::error('identity','Exact stored order, render and optional package IDs are required.',400);
        $first=$this->material($shop,$orderId,$renderId,$packageId);if(is_wp_error($first))return $first;
        $again=$this->material($shop,$orderId,$renderId,$packageId);if(is_wp_error($again))return $again;
        if($first!==$again)return self::error('changed','Stored inspection identities or current source evidence changed during the read.');
        // Admit response release after the last business-data read, in the same actor's current signed scope.
        if($actor<1||!is_user_logged_in()||get_current_user_id()!==$actor)return self::error('access','The authenticated inspection actor changed during the read.',403);
        foreach(['manage_digiforge_orders','manage_digiforge_pod','manage_digiforge_connections'] as $cap){
            $access=ShopAccess::authorize($shop,$cap,$actor);if(is_wp_error($access))return $access;
        }
        if(!is_user_logged_in()||get_current_user_id()!==$actor)return self::error('access','The authenticated inspection actor changed during final admission.',403);
        $source=$first['source'];$render=$first['render'];$package=$first['package'];
        $current=$first['current'];$blockers=['ARTIFACT_CERTIFICATION_NOT_PERFORMED','SEPARATE_PRODUCTION_AUTHORIZATION_REQUIRED'];
        if($current['state']==='BLOCKED')$blockers[]='CURRENT_BUYER_INPUT_REQUIRED';
        if(empty($source['readiness']['ready']))$blockers[]='ORDER_NOT_READY';
        $renderReviewed=$render['review_status']==='APPROVED'&&(int)$render['reviewed_by']>0&&!empty($render['reviewed_at']);
        if(!$renderReviewed)$blockers[]='RENDER_VISUAL_REVIEW_REQUIRED';
        $packageView=null;
        if($package===null)$blockers[]='REVIEW_PACKAGE_NOT_SELECTED';
        else{
            $packageReviewed=$package['state']==='APPROVED_PACKAGE'&&(int)$package['approved_by']>0&&!empty($package['approved_at']);
            $readinessMatches=hash_equals((string)$package['readiness_hash'],hash('sha256',(string)wp_json_encode($source['readiness'])));
            if(!$packageReviewed)$blockers[]='PACKAGE_HUMAN_REVIEW_REQUIRED';
            if(!$readinessMatches)$blockers[]='PACKAGE_READINESS_STALE';
            $packageView=['id'=>(int)$package['id'],'render_evidence_id'=>(int)$package['render_evidence_id'],'provider_mapping_id'=>(int)$package['provider_mapping_id'],'ownership_mapping_id'=>(int)$package['ownership_mapping_id'],'package_hash'=>$package['package_hash'],'state'=>$package['state'],'stored_human_review_recorded'=>$packageReviewed,'readiness_matches_current'=>$readinessMatches];
        }
        return ['state'=>'STORED_ORDER_ARTIFACT_INSPECTED','shop_key'=>$shop,'order_id'=>$orderId,'environment'=>$source['order']['environment'],
            'line_item_id'=>(int)$first['line']['id'],'product_version_id'=>(int)$first['line']['product_version_id'],
            'render'=>['id'=>(int)$render['id'],'provider_mapping_id'=>(int)$render['provider_mapping_id'],'template_key'=>$render['template_key'],'template_version'=>$render['template_version'],'template_sha256'=>$render['template_sha256'],'personalization_evidence_hash'=>$render['personalization_evidence_hash'],'output_sha256'=>$render['output_sha256'],'evidence_hash'=>$render['evidence_hash'],'review_status'=>$render['review_status'],'stored_human_review_recorded'=>$renderReviewed],
            'package'=>$packageView,'current_input_state'=>$current['state'],'current_input_blocker'=>$current['code'],'inspection_hash'=>Validator::hash($first),
            'hash_evidence'=>'RECORDED_ASSERTIONS_ONLY','artifact_certification'=>'NOT_PERFORMED','blockers'=>$blockers,'production_ready'=>false,'supplier_certified'=>false,'provider_identity_verified'=>false,'release_hold'=>true,'retry_permitted'=>false,'adapter_invoked'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];
    }

    public function render(string $shop,int $orderId,int $renderId,?int $packageId=null): void
    {
        echo '<section class="df-panel"><h3>Order artifact inspection</h3>';
        $result=$this->inspect($shop,$orderId,$renderId,$packageId);
        if(is_wp_error($result)){echo '<div role="alert" class="df-notice df-notice-error">'.esc_html($result->get_error_message()).'</div></section>';return;}
        $render=$result['render'];
        echo '<p>Order #'.esc_html((string)$result['order_id']).' / line #'.esc_html((string)$result['line_item_id']).' / render #'.esc_html((string)$render['id']).'</p>';
        echo '<p>Template '.esc_html($render['template_key'].' @ '.$render['template_version']).' · mapping #'.esc_html((string)$render['provider_mapping_id']).' · stored review '.esc_html($render['review_status']).'</p>';
        echo '<p>Recorded output hash <code>'.esc_html($render['output_sha256']).'</code>. Stored hashes and review states do not certify current artifact bytes.</p>';
        echo '<p>Current buyer input: '.esc_html($result['current_input_state']).'</p>';
        echo '<form method="get" action="'.esc_url(remove_query_arg(['df_view','df_shop','df_order_id','df_render_id','df_package_id'],OperatorReturnUrl::current())).'">';
        foreach(['df_view'=>'orders','df_shop'=>$shop,'df_order_id'=>(string)$orderId,'df_render_id'=>(string)$renderId] as $field=>$value)echo '<input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr($value).'">';
        echo '<label>Stored package ID <input type="number" name="df_package_id" min="1" max="2147483647" step="1" value="'.esc_attr($packageId===null?'':(string)$packageId).'" required></label> <button class="df-button df-button-compact" type="submit">Inspect this stored package</button></form>';
        if($result['package']!==null)echo '<p>Stored package #'.esc_html((string)$result['package']['id']).' · '.esc_html($result['package']['state']).'</p>';
        echo '<p>Artifact certification: '.esc_html($result['artifact_certification']).'. Existing artifact-owner certification and separate production review remain required.</p>';
        echo '<p>Blockers: '.esc_html(implode(', ',$result['blockers'])).'</p><p>Release hold: YES. Adapter invoked: NO. External execution authority: NO.</p></section>';
    }

    /** All private source material stays internal to the final identity recheck. */
    private function material(string $shop,int $orderId,int $renderId,?int $packageId): array|WP_Error
    {
        $source=(new HeldFulfillmentPreparation())->forOrder($shop,$orderId);if(is_wp_error($source))return $source;
        global $wpdb;
        $rows=$this->rows($wpdb->prepare('SELECT id,order_id,provider_mapping_id,template_key,template_version,template_sha256,personalization_evidence_hash,output_sha256,evidence_hash,review_status,reviewed_by,reviewed_at,external_execution_performed FROM '.Tables::pod_render_evidence().' WHERE id=%d AND order_id=%d LIMIT 2',$renderId,$orderId));
        if(is_wp_error($rows))return $rows;
        if(count($rows)!==1)return self::error('render_scope','The exact stored render is unavailable for this order.',404);
        $render=$rows[0];
        if((int)$render['external_execution_performed']!==0||!in_array($render['review_status'],['UNREVIEWED','APPROVED','REJECTED'],true)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',(string)$render['template_key'])||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$render['template_version']))return self::error('render_identity','A bounded held render identity is required.');
        foreach(['template_sha256','personalization_evidence_hash','output_sha256','evidence_hash'] as $field)if(!preg_match('/^[a-f0-9]{64}$/D',(string)$render[$field]))return self::error('render_identity','Stored render identity hashes are invalid.');
        $matches=array_values(array_filter($source['lines'],static fn(array $line):bool=>(int)$line['provider_mapping_id']===(int)$render['provider_mapping_id']));
        if(count($matches)!==1)return self::error('line_identity','The render must belong to one exact mapped order line.');
        $line=$matches[0];
        if($line['validation_status']!=='VALIDATED'||(int)$line['product_version_id']!==(int)$line['mapping_product_version_id']||$line['environment']!==$source['order']['environment']||$line['mapping_environment']!==$line['environment'])return self::error('line_identity','Stored render, product version and order environment must match exactly.');
        $owners=[];
        foreach($source['line_identity_snapshot']['items'] as $identity)if((int)$identity['line']['id']===(int)$line['id'])$owners=$identity['ownership'];
        if(count($owners)!==1)return self::error('ownership','Exact current stored ownership is required.');
        $package=null;
        if($packageId!==null){
            $packages=$this->rows($wpdb->prepare('SELECT id,order_id,render_evidence_id,provider_mapping_id,ownership_mapping_id,readiness_hash,package_hash,state,approved_by,approved_at,external_execution_authorized,external_execution_performed FROM '.Tables::pod_authorization_packages().' WHERE id=%d AND order_id=%d AND render_evidence_id=%d AND provider_mapping_id=%d AND ownership_mapping_id=%d LIMIT 2',$packageId,$orderId,$renderId,(int)$render['provider_mapping_id'],(int)$owners[0]['id']));
            if(is_wp_error($packages))return $packages;
            if(count($packages)!==1)return self::error('package_scope','The exact stored package is unavailable.',404);
            $package=$packages[0];
            if((int)$package['order_id']!==$orderId||(int)$package['render_evidence_id']!==$renderId||(int)$package['provider_mapping_id']!==(int)$render['provider_mapping_id']||(int)$package['ownership_mapping_id']!==(int)$owners[0]['id']||(int)$package['external_execution_authorized']!==0||(int)$package['external_execution_performed']!==0)return self::error('package_scope','The held package must match this exact order, render, mapping and current ownership.');
            if(!in_array($package['state'],['REVIEW_REQUIRED','APPROVED_PACKAGE'],true)||!preg_match('/^[a-f0-9]{64}$/D',(string)$package['readiness_hash'])||!preg_match('/^[a-f0-9]{64}$/D',(string)$package['package_hash']))return self::error('package_identity','Stored held package metadata is invalid.');
        }
        $current=(new PersonalizationCurrentness())->forRender($orderId,(int)$render['provider_mapping_id'],$render['personalization_evidence_hash'],$render);
        if(is_wp_error($current)){
            if(($current->get_error_data()['status']??409)===503)return self::error('evidence_unavailable','Current buyer-input evidence is unavailable.',503);
            $current=['state'=>'BLOCKED','code'=>$current->get_error_code()];
        }else{
            if($current['order_line_item_id']!==(int)$line['id'])return self::error('changed','The current buyer-input line changed during inspection.');
            $current=['state'=>'CURRENT_TYPED_INPUT','code'=>null,'identity'=>$current];
        }
        return ['source'=>$source,'render'=>$render,'line'=>$line,'package'=>$package,'current'=>$current];
    }

    private function rows(string $sql): array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);
        return !is_array($rows)||$wpdb->last_error!==''?self::error('evidence_unavailable','Exact stored artifact inspection evidence could not be read.',503):$rows;
    }

    private static function error(string $code,string $message,int $status=409): WP_Error
    {
        return new WP_Error('order_artifact_inspection_'.$code,$message,['status'=>$status,'artifact_certification'=>'NOT_PERFORMED','release_hold'=>true,'external_execution_authorized'=>false]);
    }
}
