<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Database\Tables;
use DigiForge\Security\ShopAccess;

/** Owner-scoped projection only. Never returns raw options, OAuth state, tokens or grants. */
final class DriveGoogleReadModel
{
    public function __construct(private ?DriveGrantStore $store=null,private ?DriveGoogleGate $gate=null){}
    private static function key(string $kind,string $id):string{return DriveGoogleOAuth::key('operation','workflow:'.$kind.':'.$id);}
    public function snapshot(string $shop,?string $focus=null):array
    {
        $empty=['state'=>'UNAVAILABLE','sources'=>[],'plans'=>[],'truncated'=>false];
        if($shop!=='digital'||ShopAccess::authorize('digital','manage_digiforge_products')!==true)return $empty+['reason'=>'SELECT_ASSIGNED_DIGITAL_SHOP'];
        $this->store??=new DriveWordPressGrantStore();$this->gate??=new DriveWordPressGoogleGate();
        try{
            global $wpdb;$wpdb->last_error='';$sources=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::asset_revisions().' WHERE mime_type=%s AND provenance LIKE %s ORDER BY id DESC LIMIT 21','application/pdf','%'.$wpdb->esc_like('digiforge_external_document').'%'),ARRAY_A);
            if($wpdb->last_error!==''||!is_array($sources))return $empty;
            $items=[];foreach(array_slice($sources,0,20) as $r){
                $p=json_decode($r['provenance'],true);if(!is_array($p))continue;
                $b=['id'=>str_repeat('0',64),'installation_sha256'=>hash('sha256',site_url()),'environment'=>wp_get_environment_type(),'shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,'actor_id'=>get_current_user_id(),'product_id'=>$p['binding']['product_id']??null,'product_version_id'=>$p['binding']['product_version_id']??null,'revision_id'=>(int)$r['id'],'source_sha256'=>$p['source_sha256']??null];
                try{$b=DriveGoogleOAuth::binding($b);}catch(\Throwable){continue;}
                if($this->gate->owns($b))$items[]=['revision_id'=>$b['revision_id'],'product_id'=>$b['product_id'],'version_id'=>$b['product_version_id'],'source_sha256'=>$b['source_sha256'],'enabled'=>$this->canOperate($b)];
            }
            $wpdb->last_error='';$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 501',$wpdb->esc_like('digiforge_google_operation_').'%'));
            if($wpdb->last_error!==''||!is_array($names))return $empty;
            $plans=[];foreach($names as $name){$r=$this->store->read($name);if($r===null)return $empty;if(isset($r['flow_sha256'],$r['binding']['id'])){$v=$this->project($r);if($v!==null)$plans[$v['id']]=$v;if(count($plans)>=10)break;}}
            if(is_string($focus)&&preg_match('/^[a-f0-9]{64}$/D',$focus)===1){$r=$this->store->read(self::key('plan',$focus));if($r===null)return $empty;$v=$this->project($r);if($v!==null)$plans=[$v['id']=>$v]+$plans;}
            return ['state'=>'AVAILABLE','sources'=>$items,'plans'=>array_slice(array_values($plans),0,10),'truncated'=>count($names)>500||count($sources)>20||count($plans)>=10];
        }catch(\Throwable){return $empty;}
    }
    private function canOperate(array $b):bool
    {
        return current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_settings')
            &&$this->gate->allows($b)&&(new DriveGoogleWordPress())->services()!==null;
    }
    private function project(array $p):?array
    {
        try{
            $b=DriveGoogleOAuth::binding($p['binding']??[]);
            if($b['actor_id']!==get_current_user_id()||$b['installation_sha256']!==hash('sha256',site_url())||$b['environment']!==wp_get_environment_type()||!$this->gate->owns($b))return null;
            if(!in_array($p['state']??null,['INITIALIZING','OAUTH_PENDING','CONNECTED'],true))return null;
            $t=$this->store->read(self::key('transfer',$b['id']));if($t===null)throw new \RuntimeException();
            if($t!==[]&&($t['binding']??null)!==$b)return null;
            $connection='MISSING';$expires=null;$c=[];$connectionId=$p['connection']['connection_id']??null;
            if(is_string($p['connection']['connection_id']??null)){$c=$this->store->read(DriveGoogleOAuth::key('connection',$p['connection']['connection_id']));if($c===null)throw new \RuntimeException();
                if(($c['binding']??null)===$b&&($c['id']??null)===$connectionId&&($c['account_id']??null)===($p['connection']['account_id']??null)&&is_string($c['ciphertext']??null)&&$c['ciphertext']!==''&&in_array($c['state']??null,['ACTIVE','UNKNOWN','REFRESHING','REVOKED'],true)){$connection=$c['state'];$expires=is_int($c['expires_at']??null)?$c['expires_at']:null;if($connection==='ACTIVE'&&($expires===null||$expires<=time()))$connection='EXPIRED';}}
            $jobs=[];$running=false;$jobsKnown=true;foreach($t['jobs']??[] as $stage=>$id){if(!is_int($id)||!is_string($stage))return null;$j=(new DriveWordPressQueueJobs())->read($id);if($j===null||$j['transfer']!==$b['id']||$j['stage']!==$stage){$jobsKnown=false;$jobs[]=['id'=>$id,'stage'=>$stage,'state'=>'UNAVAILABLE','scheduler'=>'UNAVAILABLE'];continue;}$running=$running||$j['state']==='RUNNING';$jobs[]=['id'=>$id,'stage'=>$stage,'state'=>$j['state'],'scheduler'=>self::scheduled($id)];}
            $enabled=$jobsKnown&&$this->canOperate($b);$grant=$t['grant']??[];$journal=is_string($grant['nonce']??null)?$this->store->read(DriveGrantJournal::key($grant)):[];
            $grantValid=($grant['issuer']??null)==='digiforge_google_v1'&&DriveExecutionGrant::validate($grant,$b,'folder',time(),false,true)&&is_array($journal['attempts']??null)&&count($journal['attempts'])<($grant['remaining_attempts']??0)&&($grant['connection_id']??null)===$connectionId&&($grant['account_id']??null)===($c['account_id']??null)&&($grant['connection_generation']??null)===($c['generation']??null)&&is_array($journal)&&($journal['grant']??null)===$grant&&($journal['revoked']??null)===false&&is_int($grant['expires_at']??null)&&$grant['expires_at']>time();
            $actions=[];$complete=($t['state']??null)==='TECHNICAL_QA_COMPLETE';$connected=$p['state']==='CONNECTED';
            $actions['queue']=$enabled&&$connected&&!$running&&!$complete&&($t===[]||($grantValid&&($t['state']??null)==='QUEUED'))&&$connection==='ACTIVE';
            $actions['refresh']=$enabled&&$connected&&!$running&&!$complete&&in_array($connection,['ACTIVE','EXPIRED'],true);
            $actions['renew']=$enabled&&$connected&&!$running&&!$complete&&$t!==[]&&$connection==='ACTIVE';
            foreach(['original_upload','native_conversion'] as $role)$actions[$role]=$enabled&&!$running&&$grantValid&&$connection==='ACTIVE'&&isset($t['claims'][$role])&&!isset($t['results'][$role]);
            return ['id'=>$b['id'],'product_id'=>$b['product_id'],'version_id'=>$b['product_version_id'],'revision_id'=>$b['revision_id'],'source_sha256'=>$b['source_sha256'],
                'state'=>$t['state']??$p['state'],'connection'=>$connection,'credential_expires_at'=>$expires,'grant_expires_at'=>is_int($grant['expires_at']??null)?$grant['expires_at']:null,'grant_state'=>$t===[]?'NOT_ISSUED':($grantValid?'CURRENT':'EXPIRED_REVOKED_OR_UNAVAILABLE'),
                'jobs'=>$jobs,'actions'=>$actions,'provider_acceptance'=>'UNKNOWN','commercial_approval'=>false];
        }catch(\Throwable){return null;}
    }
    private static function scheduled(int $id):string
    {
        if(!function_exists('as_get_scheduled_actions')||!class_exists('ActionScheduler'))return 'UNAVAILABLE';
        try{$ids=as_get_scheduled_actions(['hook'=>'digiforge_google_drive_job','args'=>[$id],'group'=>'digiforge-google','per_page'=>2,'orderby'=>'date','order'=>'DESC'],'ids');if(!is_array($ids)||count($ids)!==1)return 'UNKNOWN';$s=\ActionScheduler::store()->get_status((int)$ids[0]);return in_array($s,['pending','in-progress','failed','complete','canceled'],true)?strtoupper($s):'UNKNOWN';}catch(\Throwable){return 'UNAVAILABLE';}
    }
}
