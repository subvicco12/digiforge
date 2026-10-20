<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Read-only record identity resolution, never measurement, supplier certification or authority. */
final class AcceptanceEvidenceResolver
{
    /** Explicit codec over an actual independently reviewed policy; never aliases opaque references. */
    public static function policyReference(string $shop,string $key): array|WP_Error
    {
        $access=self::access($shop);if(is_wp_error($access))return $access;
        $state=self::policy($shop,$key);
        if(is_wp_error($state))return self::error();
        return ['shop_key'=>$shop,'reference'=>'local://reviewed-policy/v1/'.$key.'/'.$state['version'].'/'.$state['revision'],'sha256'=>$state['proposal_hash']];
    }

    /** Environment is the caller's explicit evidence context, not an activation or runtime override. */
    public static function resolve(string $shop,string $criterion,string $scope,string $environment): array|WP_Error
    {
        $access=self::access($shop);if(is_wp_error($access))return $access;
        if(!in_array($environment,ReviewedScopedPolicy::ENVIRONMENTS,true))return self::error();
        $out=['shop_key'=>$shop,'criterion'=>$criterion,'scope'=>$scope,'environment'=>$environment,'resolution_state'=>'UNKNOWN','value_state'=>'UNKNOWN','evidence_state'=>'UNVERIFIED','matched_record_count'=>0,'references'=>[],'read_only'=>true,'external_execution_authorized'=>false,'supplier_approved'=>false,'publishing_allowed'=>false,'ai_execution_allowed'=>false];
        $contract=ReviewedAcceptanceContract::current($shop,$criterion,$scope);
        if(is_wp_error($contract))return $out;
        if($contract['payload']['version']===2&&$contract['payload']['definition']['environment']!==$environment)return $out;
        $refs=[];
        $collect=static function(array $node)use(&$collect,&$refs):void {
            if(isset($node['shop_key'],$node['reference'],$node['sha256'])) {
                $id=$node['reference'];
                if(isset($refs[$id])){$refs[$id]['occurrences']++;if($refs[$id]['sha256']!==$node['sha256'])$refs[$id]['conflict']=true;}
                else $refs[$id]=$node+['occurrences'=>1,'conflict'=>false];
                return;
            }
            foreach($node as $child)if(is_array($child))$collect($child);
        };
        $collect($contract['payload']['definition']);
        $snapshots=[];
        foreach($refs as $ref) {
            $result=['reference'=>$ref['reference'],'occurrences'=>$ref['occurrences'],'state'=>'UNVERIFIED','reason'=>'UNSUPPORTED_REFERENCE'];
            if($ref['conflict']){$result['reason']='CONFLICTING_REFERENCE';$out['references'][]=$result;continue;}
            if(preg_match('#^local://reviewed-policy/v1/((sandbox|production):(SHOP|WORKFLOW):([a-z_]*):([a-z_]+))/([1-9][0-9]{0,8})/([1-9][0-9]{0,8})$#D',$ref['reference'],$parts)!==1){$out['references'][]=$result;continue;}
            $key=$parts[1];$state=self::policy($shop,$key);
            $result['reason']='RECORD_UNAVAILABLE_OR_STALE';
            if(!is_wp_error($state)&&$parts[2]===$environment&&($parts[3]==='SHOP'||$parts[4]===$contract['payload']['workflow'])
                &&$state['version']===(int)$parts[6]&&$state['revision']===(int)$parts[7]&&hash_equals($state['proposal_hash'],$ref['sha256'])) {
                $result=array_replace($result,['state'=>'MATCHED_LOCAL_POLICY','record_type'=>'REVIEWED_SCOPED_POLICY_V1','purpose_verified'=>false,'reason'=>'EXACT_REVIEWED_RECORD','sha256'=>$state['proposal_hash'],'record_version'=>$state['version'],'review_revision'=>$state['revision'],'effective_at'=>$state['effective_at'],'expires_at'=>$state['expires_at']]);
                $snapshots[count($out['references'])]=[$key,$state];
            }
            $out['references'][]=$result;
        }
        // No cached positive result survives a changed policy or parent review during this read.
        foreach($snapshots as $index=>[$key,$state]) {
            if(self::policy($shop,$key)!==$state)$out['references'][$index]=['reference'=>$out['references'][$index]['reference'],'occurrences'=>$out['references'][$index]['occurrences'],'state'=>'UNVERIFIED','reason'=>'RECORD_UNAVAILABLE_OR_STALE'];
        }
        if(ReviewedAcceptanceContract::current($shop,$criterion,$scope)!==$contract){$out['references']=[];return $out;}
        $out['matched_record_count']=count(array_filter($out['references'],static fn(array $ref):bool=>$ref['state']==='MATCHED_LOCAL_POLICY'));
        if($out['references']!==[]&&$out['matched_record_count']===count($out['references']))$out['resolution_state']='MATCHED_LOCAL_RECORDS';
        return $out;
    }

    private static function policy(string $shop,string $key): array|WP_Error
    {
        $state=ReviewedLocalEvidence::approved('policy',$shop,$key);
        if(is_wp_error($state)||ReviewedScopedPolicy::keyFor($state['payload'])!==$key)return self::error();
        return $state;
    }
    private static function access(string $shop): true|WP_Error
    {
        if(get_current_user_id()<1)return new WP_Error('digiforge_evidence_authentication','Authenticated selected-shop access is required.',['status'=>401]);
        if(!in_array($shop,['digital','personalized_pod'],true))return new WP_Error('digiforge_evidence_scope','An explicitly assigned active shop is required.',['status'=>403]);
        return ShopAccess::authorize($shop,'manage_digiforge');
    }
    private static function error(): WP_Error {return new WP_Error('digiforge_evidence_unavailable','An exact current independently reviewed local policy record is required.',['status'=>409,'external_execution_authorized'=>false]);}
}
