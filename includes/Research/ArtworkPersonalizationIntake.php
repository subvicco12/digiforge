<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\POD\Shop2ProductWorkflow;
use DigiForge\PortalAccess\{Store,Session};
use DigiForge\Security\ShopAccessRepository;
use WP_Error;

/** Selection references only. Proofs and approvals remain in their original immutable repositories. */
final class ArtworkPersonalizationIntake
{
    public const SCHEMA='DF_ARTWORK_PERSONALIZATION_INTAKE_1';
    private const PREFIX='digiforge_shop2_proof_intake_';

    /** Explicit authenticated review/selection boundary only; NEVER call from package export. */
    public function selectReviewed(string $shop,string $supplier,string $factory,string $proof):array|WP_Error
    {
        $ctx=$this->context($shop,$supplier,$factory);if(is_wp_error($ctx))return $ctx;
        $v=$this->proof($shop,$factory,$proof);if(is_wp_error($v))return $v;
        $key=$this->headKey($supplier,$factory);$old=$this->stored($key);if(is_wp_error($old))return $old;
        $prior=null;
        if($old!==null){$prior=$this->selection($old,$supplier,$factory);if(is_wp_error($prior))return $prior;if($prior['proof_hash']===$proof)return $this->resolve($shop,$supplier,$factory);}
        $revision=($prior['revision']??0)+1;if($revision>50)return self::deny('selection_capacity');
        $selection=['schema'=>'DF_ARTWORK_PERSONALIZATION_SELECTION_1','shop_key'=>$shop,'supplier_workflow_hash'=>$supplier,'factory_hash'=>$factory,'proof_hash'=>$v['hash'],'schema_hash'=>$v['record']['data']['schema_hash'],'review_hash'=>$v['review']['hash'],'selected_by'=>get_current_user_id(),'selected_at'=>gmdate('Y-m-d\TH:i:s\Z'),'revision'=>$revision,'parent_hash'=>$old];
        $raw=CommercialResearchContract::json(['selection'=>$selection,'seal'=>$this->seal($selection)]);$hash=hash('sha256',$raw);$versionKey=self::PREFIX.'version_'.$hash;
        if(!add_option($versionKey,$raw,'',false)&&$this->stored($versionKey)!==$raw)return self::deny('selection_write');
        if($this->stored($versionKey)!==$raw)return self::deny('selection_readback');
        if($this->scope($shop)!==$ctx['actor_scope'])return self::deny('scope_changed',403);
        global $wpdb;
        if($old===null){if(!add_option($key,$hash,'',false))return self::deny('selection_conflict');}
        else{
            $wpdb->last_error='';$changed=$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',$hash,$key,$old));
            if($changed!==1||$wpdb->last_error!=='')return self::deny('selection_conflict');
            wp_cache_delete($key,'options');
        }
        if($this->stored($key)!==$hash)return self::deny('selection_uncertain');
        return $this->resolve($shop,$supplier,$factory);
    }

    /** Supplier/factory must come from the consumer's server-side binding. No proof metadata parameter. */
    public function resolve(string $shop,string $supplier,string $factory,?string $expectedIntakeHash=null):array|WP_Error
    {
        $ctx=$this->context($shop,$supplier,$factory);if(is_wp_error($ctx))return $ctx;
        if($expectedIntakeHash!==null&&!self::hash($expectedIntakeHash))return self::deny('expected_version');
        $head=$this->stored($this->headKey($supplier,$factory));if(is_wp_error($head))return $head;
        $selection=$this->selection($head,$supplier,$factory);if(is_wp_error($selection))return $selection;
        $v=$this->proof($shop,$factory,$selection['proof_hash']);if(is_wp_error($v))return $v;
        if($selection['schema_hash']!==$v['record']['data']['schema_hash']||$selection['review_hash']!==$v['review']['hash'])return self::deny('review_version');
        $schema=(new ArtworkPersonalizationRepository())->read($shop,$selection['schema_hash']);if(is_wp_error($schema))return self::deny('schema_unavailable');
        if($schema['record']['kind']!=='SCHEMA'||$schema['record']['factory_hash']!==$factory||$schema['record']['data']['fields']!==$v['record']['data']['result']['fields'])return self::deny('schema_conflict');
        $f=$ctx['factory'];$result=$v['record']['data']['result'];
        $body=['schema'=>self::SCHEMA,'shop_key'=>$shop,'actor_scope'=>$ctx['actor_scope'],'selection'=>['hash'=>$head,'record'=>$selection],
            'versions'=>['supplier_workflow_hash'=>$supplier,'supplier_snapshot_hash'=>$ctx['supplier']['record']['snapshot_id'],'research_hash'=>$f['record']['commercial_hash'],'factory_hash'=>$factory,'factory_review_hash'=>$f['review_hash'],'artwork_version_hash'=>$f['artwork']['version_hash'],'artwork_approval_sha256'=>hash('sha256',CommercialResearchContract::json($f['artwork']['approval'])),'specification_sha256'=>$result['specification_sha256'],'artwork_sha256'=>$result['artwork_sha256'],'schema_hash'=>$schema['hash'],'proof_hash'=>$v['hash'],'review_hash'=>$v['review']['hash'],'selection_hash'=>$head],
            'supplier'=>$ctx['supplier'],'research'=>$f['record']['context'],'factory'=>['hash'=>$factory,'review_hash'=>$f['review_hash'],'review_state'=>$f['review_state'],'specification'=>$f['record']['specification']],
            'artwork'=>['version_hash'=>$f['artwork']['version_hash'],'approval'=>$f['artwork']['approval'],'provenance'=>$f['record']['provenance']],
            'field_schema'=>$schema,'proof'=>$v,'review'=>$v['review'],'external_execution_authorized'=>false,'production_authorized'=>false,'package_approval_issued'=>false,'provider_certified'=>false,'release_hold'=>true];
        // Re-read current proof/source/authority and selection after materializing the private snapshot.
        $again=$this->proof($shop,$factory,$v['hash']);$latest=$this->context($shop,$supplier,$factory);
        if(is_wp_error($again)||is_wp_error($latest)||$again!==$v||$latest!==$ctx||$this->stored($this->headKey($supplier,$factory))!==$head)return self::deny('changed_during_read');
        $digest=hash('sha256',CommercialResearchContract::json($body));
        if($expectedIntakeHash!==null&&!hash_equals($expectedIntakeHash,$digest))return self::deny('stale_intake');
        return ['intake_hash'=>$digest]+$body;
    }

    private function context(string $shop,string $supplier,string $factory):array|WP_Error
    {
        $scope=$this->scope($shop);if(is_wp_error($scope))return $scope;
        if(!self::hash($supplier)||!self::hash($factory))return self::deny('source_reference');
        $repo=new Shop2ProductWorkflow();$link=$repo->read($supplier);if(is_wp_error($link))return self::deny('supplier_unavailable');
        $f=(new DesignFactoryRepository())->read($shop,$factory);if(is_wp_error($f)||$f['review_state']!=='HUMAN_APPROVED'||!is_array($f['artwork']['approval']??null))return self::deny('artwork_unapproved');
        if(is_wp_error($repo->context($link,$f['record']['commercial_hash'])))return self::deny('foreign_research');
        $g=$f['record']['specification']['geometry'];
        if($g['family']!==$link['record']['family']||is_wp_error($repo->geometry($link,$g['width_px'],$g['height_px'])))return self::deny('supplier_geometry');
        return ['actor_scope'=>$scope,'supplier'=>$link,'factory'=>$f];
    }
    private function proof(string $shop,string $factory,string $proof):array|WP_Error
    {
        $v=(new ArtworkPersonalizationRepository())->read($shop,$proof);
        if(is_wp_error($v)||$v['record']['kind']!=='PROOF'||$v['record']['factory_hash']!==$factory||($v['review']['decision']??null)!=='APPROVE'||$v['record']['data']['result']['state']!=='MANUAL_REVIEW')return self::deny('reviewed_proof_required');
        return $v;
    }
    private function scope(string $shop):array|WP_Error
    {
        if($shop!=='personalized_pod'||!Shop2ProductWorkflow::access())return self::deny('scope',403);
        $actor=get_current_user_id();$cookie=$_COOKIE[LOGGED_IN_COOKIE]??null;
        if(!is_string($cookie)||wp_validate_auth_cookie($cookie,'logged_in')!==$actor)return self::deny('session',403);
        $profile=Store::read($actor);$acl=(new ShopAccessRepository())->read($shop);
        if(!is_array($profile)||is_wp_error($acl)||!Session::valid($profile))return self::deny('current_authority',403);
        $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_products'];$user=get_userdata($actor);
        foreach($caps as $cap)if(!$user||!user_can($user,$cap))return self::deny('capability',403);
        return ['actor_id'=>$actor,'shop_key'=>$shop,'profile_revision'=>$profile['revision'],'profile_generation'=>$profile['generation'],'profile_hash'=>Store::hash($profile),'scope_revision'=>$acl['revision'],'scope_sha256'=>hash('sha256',CommercialResearchContract::json($acl)),'required_capabilities'=>$caps,'current_session_verified'=>true];
    }
    private function selection(mixed $hash,string $supplier,string $factory):array|WP_Error
    {
        $first=null;$seen=[];$revision=null;
        while($hash!==null){
            if(!self::hash($hash)||isset($seen[$hash])||count($seen)>=50)return self::deny('selection_corrupt');$seen[$hash]=true;
            $raw=$this->stored(self::PREFIX.'version_'.$hash);if(!is_string($raw)||strlen($raw)>4096||!hash_equals($hash,hash('sha256',$raw)))return self::deny('selection_unavailable');
            try{$envelope=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$s=$envelope['selection']??null;
                if(!is_array($envelope)||array_keys($envelope)!==['selection','seal']||!is_array($s)||array_keys($s)!==['schema','shop_key','supplier_workflow_hash','factory_hash','proof_hash','schema_hash','review_hash','selected_by','selected_at','revision','parent_hash']||!is_string($envelope['seal'])||!hash_equals($this->seal($s),$envelope['seal'])||$s['schema']!=='DF_ARTWORK_PERSONALIZATION_SELECTION_1'||$s['shop_key']!=='personalized_pod'||$s['supplier_workflow_hash']!==$supplier||$s['factory_hash']!==$factory||!is_int($s['selected_by'])||$s['selected_by']<1||!is_int($s['revision'])||$s['revision']<1||$s['revision']>50||!is_string($s['selected_at'])||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$s['selected_at']))throw new \RuntimeException();
                foreach(['proof_hash','schema_hash','review_hash'] as $key)if(!self::hash($s[$key]))throw new \RuntimeException();
                if($revision!==null&&$s['revision']!==$revision-1)throw new \RuntimeException();if(($s['revision']===1)!==($s['parent_hash']===null))throw new \RuntimeException();
            }catch(\Throwable){return self::deny('selection_corrupt');}
            $first??=$s;$revision=$s['revision'];$hash=$s['parent_hash'];
        }
        return $first??self::deny('selection_missing');
    }
    private function headKey(string $supplier,string $factory):string {return self::PREFIX.'head_'.hash('sha256','personalized_pod:'.$supplier.':'.$factory);}
    private function seal(array $selection):string {return hash_hmac('sha256','DF_ARTWORK_PERSONALIZATION_SELECTION_1:'.CommercialResearchContract::json($selection),wp_salt('auth'));}
    private function stored(string $key):string|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$key));if(class_exists(\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::class))\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::observeOption($key,$raw);return $wpdb->last_error===''&&($raw===null||is_string($raw))?$raw:self::deny('storage_unavailable',503);}
    private static function hash(mixed $value):bool {return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
    private static function deny(string $code,int $status=409):WP_Error {return new WP_Error('personalization_intake_'.$code,'Current selected reviewed local proof required: '.$code.'.',['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false,'production_authorized'=>false,'release_hold'=>true]);}
}
