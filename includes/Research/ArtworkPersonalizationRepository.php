<?php
declare(strict_types=1);
namespace DigiForge\Research;
use WP_Error;

/** Immutable local schema/sample reviews. Reuses approved design custody, never business/SKU authority. */
final class ArtworkPersonalizationRepository
{
    private const PREFIX='digiforge_shop2_personalization_';
    public function define(string $shop,string $factory,array $fields,?string $parent=null):array|WP_Error
    {
        $context=$this->context($shop,$factory);if(is_wp_error($context))return $context;
        try{$fields=ArtworkPersonalizationContract::fields($context['record']['specification'],$fields);}catch(\Throwable){return self::error('Exact native text/date mapping required; image/crop fields require manual review.');}
        if($parent!==null){$prior=$this->read($shop,$parent);if(is_wp_error($prior))return $prior;if($prior['record']['kind']!=='SCHEMA'||$prior['record']['workspace_id']!==$this->workspace($context))return self::error('Schema revision belongs to another workspace.');}
        return $this->save($context,'SCHEMA',$parent,['fields'=>$fields]);
    }
    public function sample(string $shop,string $schema,array $values,?string $parent=null):array|WP_Error
    {
        $v=$this->read($shop,$schema);if(is_wp_error($v))return $v;if($v['record']['kind']!=='SCHEMA')return self::error('Exact schema version required.');
        $context=$this->context($shop,$v['record']['factory_hash']);if(is_wp_error($context))return $context;
        if($parent!==null){$prior=$this->read($shop,$parent);if(is_wp_error($prior))return $prior;if($prior['record']['kind']!=='PROOF'||$prior['record']['data']['schema_hash']!==$schema)return self::error('Sample revision must retain its exact schema.');}
        try{$result=ArtworkPersonalizationContract::proof($context['record']['specification'],$context['record']['asset']['svg'],$v['record']['data']['fields'],$values);}catch(\Throwable){return self::error('Missing, invalid, over-cap or invalid-calendar customer input.');}
        return $this->save($context,'PROOF',$parent??$schema,['schema_hash'=>$schema,'result'=>$result]);
    }
    public function decide(string $shop,string $hash,string $decision,string $note):array|WP_Error
    {
        $v=$this->read($shop,$hash);if(is_wp_error($v))return $v;
        if($v['record']['kind']!=='PROOF'||$v['review']!==null||!in_array($decision,['APPROVE','REJECT'],true)||trim($note)===''||strlen($note)>2000||preg_match('//u',$note)!==1)return self::error('One explicit human decision with bounded rationale required.');
        if($decision==='APPROVE'&&$v['record']['data']['result']['state']!=='MANUAL_REVIEW')return self::error('Unsupported/overflow HOLD cannot be approved through a local proof.');
        $context=$this->context($shop,$v['record']['factory_hash']);if(is_wp_error($context))return $context;
        $review=$this->save($context,'REVIEW',$hash,['proof_hash'=>$hash,'decision'=>$decision,'note'=>$note]);if(is_wp_error($review))return $review;
        $name=self::PREFIX.'decision_'.$hash;
        if(!add_option($name,$review['hash'],'',false)||$this->stored($name)!==$review['hash'])return self::error('Decision pointer uncertain; inspect exact proof before retry.');
        return $this->read($shop,$hash);
    }
    public function read(string $shop,string $hash):array|WP_Error
    {
        if(!$this->allowed($shop))return self::error('Authenticated Shop 2 reviewer required.',403);
        $r=$this->record($hash);if(is_wp_error($r))return $r;
        $context=$this->context($shop,$r['factory_hash']);if(is_wp_error($context))return $context;
        if($r['workspace_id']!==$this->workspace($context))return self::error('Workspace/source identity conflict.');
        $review=null;
        try{
            if($r['kind']==='SCHEMA'){
                if(array_keys($r['data'])!==['fields']||ArtworkPersonalizationContract::fields($context['record']['specification'],$r['data']['fields'])!==$r['data']['fields'])throw new \RuntimeException();
            }elseif($r['kind']==='PROOF'){
                if(array_keys($r['data'])!==['schema_hash','result']||!is_array($r['data']['result']))throw new \RuntimeException();
                $schema=$this->record($r['data']['schema_hash']);if(is_wp_error($schema)||$schema['kind']!=='SCHEMA'||$schema['factory_hash']!==$r['factory_hash']||$schema['workspace_id']!==$r['workspace_id']||array_keys($schema['data'])!==['fields'])throw new \RuntimeException();
                $expected=ArtworkPersonalizationContract::proof($context['record']['specification'],$context['record']['asset']['svg'],$schema['data']['fields'],$r['data']['result']['samples']);
                if(CommercialResearchContract::json($expected)!==CommercialResearchContract::json($r['data']['result']))throw new \RuntimeException();
                $pointer=$this->stored(self::PREFIX.'decision_'.$hash);if(is_wp_error($pointer))return $pointer;
                if($pointer!==null){
                    $d=$this->record($pointer);if(is_wp_error($d)||$d['kind']!=='REVIEW'||$d['parent_hash']!==$hash||$d['factory_hash']!==$r['factory_hash']||$d['workspace_id']!==$r['workspace_id']||array_keys($d['data'])!==['proof_hash','decision','note']||$d['data']['proof_hash']!==$hash||!in_array($d['data']['decision'],['APPROVE','REJECT'],true)||!is_string($d['data']['note'])||trim($d['data']['note'])===''||strlen($d['data']['note'])>2000)throw new \RuntimeException();
                    if($d['data']['decision']==='APPROVE'&&$expected['state']!=='MANUAL_REVIEW')throw new \RuntimeException();
                    $review=['hash'=>$pointer,'decision'=>$d['data']['decision'],'note'=>$d['data']['note'],'actor'=>$d['created_by'],'created_at'=>$d['created_at'],'production_authorized'=>false];
                }
            }else return self::error('Review records are opened through their exact proof.');
        }catch(\Throwable){return self::error('Stored personalization or preview lineage is invalid.');}
        return ['hash'=>$hash,'record'=>$r,'review'=>$review,'release_hold'=>true,'external_execution_authorized'=>false];
    }
    public function recent(string $shop,string $factory):array|WP_Error
    {
        $context=$this->context($shop,$factory);if(is_wp_error($context))return $context;
        global $wpdb;$wpdb->last_error='';$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 50',$wpdb->esc_like(self::PREFIX.'version_').'%'));
        if($wpdb->last_error!==''||!is_array($names))return self::error('Saved evidence index unavailable.');
        $out=[];foreach($names as $name){$hash=substr($name,strlen(self::PREFIX.'version_'));$r=$this->record($hash);if(is_wp_error($r))return $r;if($r['factory_hash']!==$factory||$r['kind']==='REVIEW')continue;$v=$this->read($shop,$hash);if(is_wp_error($v))return $v;$out[]=$v;}
        return $out;
    }
    public function history(string $shop,string $hash):array|WP_Error
    {
        $out=[];$seen=[];while($hash!==null){if(count($out)>=50||isset($seen[$hash]))return self::error('History exceeds local bound.');$seen[$hash]=true;$v=$this->read($shop,$hash);if(is_wp_error($v))return $v;$out[]=$v;$hash=$v['record']['parent_hash'];}return $out;
    }
    private function save(array $context,string $kind,?string $parent,array $data):array|WP_Error
    {
        $r=['schema_version'=>1,'shop_key'=>'personalized_pod','kind'=>$kind,'parent_hash'=>$parent,'factory_hash'=>$context['hash'],'workspace_id'=>$this->workspace($context),'created_by'=>get_current_user_id(),'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>$data];
        $raw=CommercialResearchContract::json($r);if(strlen($raw)>160000)return self::error('Local evidence exceeds byte bound.');$hash=hash('sha256',$raw);$key=self::PREFIX.'version_'.$hash;
        if(!add_option($key,$raw,'',false)||$this->stored($key)!==$raw)return self::error('Immutable write/readback failed; inspect before retry.');
        return $kind==='REVIEW'?['hash'=>$hash,'record'=>$r]:$this->read('personalized_pod',$hash);
    }
    private function context(string $shop,string $factory):array|WP_Error
    {
        if(!$this->allowed($shop))return self::error('Authenticated Shop 2 reviewer required.',403);
        $v=(new DesignFactoryRepository())->read($shop,$factory);if(is_wp_error($v))return $v;
        return ($v['review_state']??null)==='HUMAN_APPROVED'&&is_array($v['record']['asset']??null)?$v:self::error('Confirmed approved original artwork required; selection or review intent is insufficient.');
    }
    private function workspace(array $context):string {return hash('sha256','personalized_pod:'.$context['record']['commercial_hash']);}
    private function allowed(string $shop):bool {return $shop==='personalized_pod'&&\DigiForge\Security\PersonalizedPodAccess::allows()&&get_current_user_id()>0&&current_user_can('manage_digiforge_pod')&&current_user_can('manage_digiforge_research');}
    private function record(mixed $hash):array|WP_Error
    {
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('Exact immutable personalization reference required.');
        $raw=$this->stored(self::PREFIX.'version_'.$hash);if(is_wp_error($raw))return $raw;
        if(!is_string($raw)||strlen($raw)>160000||!hash_equals($hash,hash('sha256',$raw)))return self::error('Stored evidence unavailable or corrupt.');
        try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r)||array_keys($r)!==['schema_version','shop_key','kind','parent_hash','factory_hash','workspace_id','created_by','created_at','data']||$r['schema_version']!==1||$r['shop_key']!=='personalized_pod'||!in_array($r['kind'],['SCHEMA','PROOF','REVIEW'],true)||!is_int($r['created_by'])||$r['created_by']<1||!is_string($r['created_at'])||!is_array($r['data']))throw new \RuntimeException();foreach(['factory_hash','workspace_id']as $k)if(!is_string($r[$k])||!preg_match('/^[a-f0-9]{64}$/D',$r[$k]))throw new \RuntimeException();if($r['parent_hash']!==null&&(!is_string($r['parent_hash'])||!preg_match('/^[a-f0-9]{64}$/D',$r['parent_hash'])))throw new \RuntimeException();return $r;}catch(\Throwable){return self::error('Invalid persisted evidence envelope.');}
    }
    private function stored(string $key):string|WP_Error|null {global $wpdb;$wpdb->last_error='';$v=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$key));return $wpdb->last_error===''&&($v===null||is_string($v))?$v:self::error('Durable evidence read unavailable.');}
    private static function error(string $message,int $status=409):WP_Error {return new WP_Error('artwork_personalization_denied',$message,['status'=>$status,'external_execution_authorized'=>false,'retry_permitted'=>false,'release_hold'=>true]);}
}
