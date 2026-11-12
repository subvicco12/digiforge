<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/** Append-only provider-discovery progress. One explicit step per invocation; never a worker/scheduler. */
final class PrintifyResearchBatch
{
    private const PREFIX='df_pod_research_batch_';
    public function __construct(private ?PrintifyResearchReader $reader=null){}
    private function reader(): PrintifyResearchReader {return $this->reader??new PrintifyResearchGateway();}
    public static function plan(string $shop,string $snapshot,array $blueprints): array
    {
        if($shop!=='personalized_pod'||!preg_match('/^[a-f0-9]{64}$/D',$snapshot)||!array_is_list($blueprints)||count($blueprints)<1||count($blueprints)>100)throw new \InvalidArgumentException('Exact Shop 2 snapshot and 1–100 blueprint IDs required.');
        foreach($blueprints as $id)if(!is_int($id)||$id<1||$id>2147483647)throw new \InvalidArgumentException('Positive bounded blueprint IDs required.');
        return ['schema_version'=>1,'shop_key'=>$shop,'snapshot_id'=>$snapshot,'blueprint_ids'=>array_values(array_unique($blueprints)),'max_requests_per_grant'=>10,'max_steps_per_invocation'=>1,'external_execution_authorized'=>false];
    }
    public function create(string $snapshot,array $blueprints): array|WP_Error
    {
        if(!self::allowed())return PrintifyResearchGateway::error('forbidden',403);
        try{$plan=self::plan('personalized_pod',$snapshot,$blueprints);}catch(\Throwable){return PrintifyResearchGateway::error('plan',400);}
        $base=(new ProductIntelligenceRepository())->read($snapshot);if(is_wp_error($base))return $base;
        $known=[];
        foreach(['coverage','shortlist','blueprints'] as $section)foreach($base['sections'][$section]['rows']??[] as $row){$candidate=$row['Blueprint ID']??null;if(is_string($candidate)&&ctype_digit($candidate))$known[(int)$candidate]=true;}
        foreach($plan['blueprint_ids'] as $blueprint)if(!isset($known[$blueprint]))return PrintifyResearchGateway::error('blueprint_source_required',400);
        $plan['actor_id']=get_current_user_id();$id=hash('sha256',PrintifyResearchGateway::canonical($plan));$result=$this->put(self::PREFIX.$id,$plan,'pod_research_batch_prepared');return is_wp_error($result)?$result:['batch_id'=>$id,'plan'=>$plan];
    }
    public function status(string $id): array|WP_Error
    {
        $plan=$this->loadPlan($id);if(is_wp_error($plan))return $plan;$items=[];
        foreach($plan['blueprint_ids'] as $blueprint){$start=$this->load(self::PREFIX.$id.'_start_'.$blueprint);if(is_wp_error($start))return $start;$done=$this->load(self::PREFIX.$id.'_done_'.$blueprint);if(is_wp_error($done))return $done;
            if($done!==null&&$start===null)return PrintifyResearchGateway::error('progress_integrity',503);
            $items[]=['blueprint_id'=>$blueprint,'state'=>$done!==null?'COLLECTED':($start!==null?'INTERRUPTED_RECONCILE_RECEIPT':'PENDING'),'snapshot_id'=>$done['snapshot_id']??null,'receipt_sha256'=>$done['receipt_sha256']??null];
        }
        return ['batch_id'=>$id,'shop_key'=>'personalized_pod','items'=>$items,'plan'=>$plan,'external_execution_authorized'=>false,'automatic_retry'=>false];
    }
    /** Uses only explicit existing providers scopes; never issues grants or expands a grant. */
    public function step(string $id,string $grant): array|WP_Error
    {
        $plan=$this->loadPlan($id);if(is_wp_error($plan))return $plan;
        return $this->locked($id,function()use($id,$grant,$plan){
            $scope=$this->reader()->describe($grant);if(is_wp_error($scope))return $scope;
            if(($scope['shop_key']??null)!=='personalized_pod'||($scope['account_id']??null)!==get_current_user_id()||!is_int($scope['max_requests']??null)||$scope['max_requests']>10||$scope['max_requests']<1||!is_array($scope['requests']??null)||count($scope['requests'])>10)return PrintifyResearchGateway::error('grant_scope',403);
            foreach($plan['blueprint_ids'] as $prior){$attempt=$this->load(self::PREFIX.$id.'_start_'.$prior);if(is_wp_error($attempt))return $attempt;if($attempt!==null&&($attempt['integration_id']??null)!==($scope['integration_id']??null))return PrintifyResearchGateway::error('integration_changed',403);}
            foreach($scope['requests'] as $index=>$request){
                if(($request['kind']??null)!=='providers'||!in_array($request['blueprint_id']??null,$plan['blueprint_ids'],true))continue;
                $blueprint=$request['blueprint_id'];$done=$this->load(self::PREFIX.$id.'_done_'.$blueprint);if(is_wp_error($done))return $done;if($done!==null)continue;
                $name=self::PREFIX.$id.'_start_'.$blueprint;$start=$this->load($name);if(is_wp_error($start))return $start;
                if($start!==null&&($start['grant_sha256']!==$grant||$start['request_index']!==$index))return PrintifyResearchGateway::error('interrupted_scope_reconciliation_required');
                $key='pi-'.substr($id,0,24).'-'.$blueprint.'-'.substr($grant,0,16).'-'.$index;
                if($start===null){$start=['grant_sha256'=>$grant,'request_index'=>$index,'request_key'=>$key,'blueprint_id'=>$blueprint,'actor_id'=>get_current_user_id(),'integration_id'=>$scope['integration_id']];$saved=$this->put($name,$start,'pod_research_step_started');if(is_wp_error($saved))return $saved;
                    $receipt=$this->reader()->collect($grant,$index,$key);
                }else{$receipt=$this->reader()->evidence($grant,hash('sha256',$key));}
                if(is_wp_error($receipt))return $receipt;
                if(($receipt['grant_sha256']??null)!==$grant||($receipt['request']??null)!==$request||($receipt['request_sha256']??null)!==hash('sha256',$key)||($receipt['integration_id']??null)!==($scope['integration_id']??null)||($receipt['account_id']??null)!==get_current_user_id())return PrintifyResearchGateway::error('receipt_scope',403);
                $base=$this->latestSnapshot($id,$plan);if(is_wp_error($base))return $base;
                $packet=(new ProductIntelligenceRepository())->read($base);if(is_wp_error($packet))return $packet;
                try{$packet=PrintifyResearchMapper::append($packet,$receipt);}catch(\Throwable){return PrintifyResearchGateway::error('response_integrity',503);}
                $saved=(new ProductIntelligenceRepository())->import($packet);if(is_wp_error($saved))return $saved;
                $sequence=$this->completedCount($id,$plan);if(is_wp_error($sequence))return $sequence;
                $out=['blueprint_id'=>$blueprint,'grant_sha256'=>$grant,'receipt_sha256'=>$receipt['sha256'],'request_sha256'=>$receipt['request_sha256'],'snapshot_id'=>$saved['snapshot_id'],'base_snapshot_id'=>$base,'sequence'=>$sequence];
                $persist=$this->put(self::PREFIX.$id.'_done_'.$blueprint,$out,'pod_research_step_collected');return is_wp_error($persist)?$persist:$this->status($id);
            }
            return $this->status($id);
        });
    }
    private function completedCount(string $id,array $plan): int|WP_Error {$count=0;foreach($plan['blueprint_ids'] as $blueprint){$done=$this->load(self::PREFIX.$id.'_done_'.$blueprint);if(is_wp_error($done))return $done;if($done!==null)$count++;}return $count;}
    private function latestSnapshot(string $id,array $plan): string|WP_Error
    {
        $latest=$plan['snapshot_id'];$sequence=-1;$seen=[];
        foreach($plan['blueprint_ids'] as $blueprint){$done=$this->load(self::PREFIX.$id.'_done_'.$blueprint);if(is_wp_error($done))return $done;if($done===null)continue;$n=$done['sequence']??null;if(!is_int($n)||isset($seen[$n]))return PrintifyResearchGateway::error('progress_integrity',503);$seen[$n]=true;if($n>$sequence){$sequence=$n;$latest=$done['snapshot_id'];}}
        if($sequence!==count($seen)-1)return PrintifyResearchGateway::error('progress_integrity',503);return $latest;
    }
    private function loadPlan(string $id): array|WP_Error
    {
        if(!self::allowed()||!preg_match('/^[a-f0-9]{64}$/D',$id))return PrintifyResearchGateway::error('forbidden',403);
        $plan=$this->load(self::PREFIX.$id);if(is_wp_error($plan))return $plan;if($plan===null||!hash_equals($id,hash('sha256',PrintifyResearchGateway::canonical($plan)))||($plan['actor_id']??null)!==get_current_user_id()||($plan['shop_key']??null)!=='personalized_pod')return PrintifyResearchGateway::error('batch_scope',403);return $plan;
    }
    private function load(string $name): array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s LIMIT 1',$name));if($wpdb->last_error!=='')return PrintifyResearchGateway::error('storage',503);if($raw===null){$wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE object_type=%s AND object_id=%s LIMIT 1','pod_research_batch',$name));return $wpdb->last_error===''&&is_array($anchors)&&$anchors===[]?null:PrintifyResearchGateway::error('missing_anchored_progress',503);}
        try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$hash=$r['record_sha256']??'';unset($r['record_sha256']);if(!hash_equals(hash('sha256',PrintifyResearchGateway::canonical($r)),$hash))throw new \InvalidArgumentException();
            $wpdb->last_error='';$anchors=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.\DigiForge\Database\Tables::audit_log().' WHERE object_type=%s AND object_id=%s LIMIT 2','pod_research_batch',$name));
            if($wpdb->last_error!==''||!is_array($anchors)||count($anchors)!==1||(json_decode($anchors[0],true)['record_sha256']??null)!==$hash)throw new \InvalidArgumentException();return $r;}catch(\Throwable){return PrintifyResearchGateway::error('storage_integrity',503);}
    }
    private function put(string $name,array $row,string $event): array|WP_Error
    {
        $existing=$this->load($name);if(is_wp_error($existing))return $existing;
        if($existing!==null){if(PrintifyResearchGateway::canonical($existing)!==PrintifyResearchGateway::canonical($row))return PrintifyResearchGateway::error('immutable_conflict');return $existing;}
        $row['record_sha256']=hash('sha256',PrintifyResearchGateway::canonical($row));
        if(!add_option($name,PrintifyResearchGateway::canonical($row),'',false))return PrintifyResearchGateway::error('storage',503);
        if(!\DigiForge\Security\Logger::write($event,['record_sha256'=>$row['record_sha256'],'shop_key'=>'personalized_pod','external_execution_authorized'=>false],'pod_research_batch',$name))return PrintifyResearchGateway::error('audit',503);
        return $row;
    }
    private function locked(string $id,callable $work): array|WP_Error
    {
        global $wpdb;$name='df-pi-batch-'.substr($id,0,45);$wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))!=='1'||$wpdb->last_error!=='')return PrintifyResearchGateway::error('busy',409);
        try{$out=$work();}catch(\Throwable){$out=PrintifyResearchGateway::error('interrupted',503);}
        $wpdb->last_error='';if((string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name))!=='1'||$wpdb->last_error!=='')return PrintifyResearchGateway::error('release_unknown',503);return $out;
    }
    public static function allowed(): bool {
        if(!is_user_logged_in())return false;
        foreach(['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'] as $cap){
            if(!current_user_can($cap))return false;
        }
        return PrintifyResearchDependencies::assigned(['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections']);
    }
}
