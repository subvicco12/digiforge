<?php
declare(strict_types=1);
namespace DigiForge\Queue;

use DigiForge\Connector\Printify\NativeSourceTransaction;
use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyStoredShopScope;
use DigiForge\Orders\{HeldFulfillmentPreparation,PersonalizationReadContext,Validator};
use DigiForge\Security\{Logger,ShopAccess};
use WP_Error;

/** Manual local queue metadata only. Never executes a handler during inspection or quarantine. */
final class Shop2HeldJobRecovery
{
    private const TYPE='shop2_held_preparation';
    private const INVENTORY_LIMIT=100;

    public static function enqueueKey(string $shop,int $orderId,int $intentId,string $planPayloadHash,string $preparationHash,int $actorId):string
    {
        return Validator::hash(['actor_id'=>$actorId,'shop_key'=>$shop,'order_id'=>$orderId,'intent_id'=>$intentId,'plan_payload_hash'=>$planPayloadHash,'preparation_hash'=>$preparationHash]);
    }

    public function inspect(string $shop,int $orderId,int $intentId,int $jobId):array|WP_Error
    {
        try {
            $actor=get_current_user_id();$scope=$this->scope($shop,$orderId,$intentId);$source=$this->load($scope,$jobId);
            $again=$this->scope($shop,$orderId,$intentId);$confirmed=$this->load($again,$jobId);
            if(Validator::hash($source)!==Validator::hash($confirmed))throw new \RuntimeException('Current held job evidence changed.');
            $this->admit($shop,$actor);return $this->view($confirmed);
        }catch(\Throwable $e){return self::error($e->getMessage());}
    }

    public function forIntent(string $shop,int $orderId,int $intentId,int $limit=25):array|WP_Error
    {
        try {
            $actor=get_current_user_id();$scope=$this->scope($shop,$orderId,$intentId);$rows=$this->inventory(false);$limit=max(1,min(50,$limit));$items=[];
            foreach($rows as $row){$payload=$this->decode($row);if(!$this->sameIntent($payload,$scope)||$payload['actor_id']!==$actor)continue;
                $item=$this->inspect($shop,$orderId,$intentId,(int)$row['id']);if(is_wp_error($item))return $item;$items[]=$item;
                if(count($items)>$limit)throw new \RuntimeException('Held job inspection exceeds its complete local bound.');
            }
            $confirmed=$this->scope($shop,$orderId,$intentId);if(Validator::hash($scope)!==Validator::hash($confirmed))throw new \RuntimeException('Current order scope changed.');
            $this->admit($shop,$actor);return ['shop_key'=>$shop,'order_id'=>$orderId,'intent_id'=>$intentId,'items'=>$items]+self::flags();
        }catch(\Throwable $e){return self::error($e->getMessage());}
    }

    public function enqueue(string $shop,int $orderId,int $intentId,string $expectedPreparationHash,string $idempotencyKey):array|WP_Error
    {
        $this->commitState='NOT_ATTEMPTED';$started=false;$committed=false;$changed=false;$context=null;
        try {
            $actor=get_current_user_id();$this->admit($shop,$actor);$this->fingerprint($expectedPreparationHash);$this->fingerprint($idempotencyKey);
            $initialScope=$this->scope($shop,$orderId,$intentId);
            $captured=PersonalizationReadContext::capture($shop,$orderId);$this->requireResult($captured);$context=$captured;
            $this->begin(true,$context);$started=true;$this->lockAdmission();$scope=$this->scope($shop,$orderId,$intentId,true,false);
            if($scope!==$initialScope)throw new \RuntimeException('Order or intent changed before source locking.');
            $before=$this->inventory(true);$this->lockPreparationSource($scope);
            $prepared=(new HeldFulfillmentPreparation($context))->prepare($shop,$orderId,$intentId);$this->requireResult($prepared);
            if(($prepared['current_evidence_hash']??null)!==$expectedPreparationHash||self::enqueueKey($shop,$orderId,$intentId,$prepared['plan_payload_hash'],$expectedPreparationHash,$actor)!==$idempotencyKey)throw new \RuntimeException('Current preparation and deterministic job identity must match.');
            $expected=['schema'=>1,'shop_key'=>$shop,'actor_id'=>$actor,'order_id'=>$orderId,'intent_id'=>$intentId,'plan_payload_hash'=>$prepared['plan_payload_hash'],'evidence_hash'=>$expectedPreparationHash];
            $existing=null;
            foreach($before as $row){$p=$this->decode($row);if(!$this->sameIntent($p,$scope))continue;
                if($existing!==null||$p['actor_id']!==$actor||!in_array($row['state'],['BLOCKED','QUEUED'],true)||$row['payload']!==wp_json_encode($expected)||$row['idempotency_key']!=='shop2-held:'.hash('sha256',$idempotencyKey))throw new \RuntimeException('Existing held work for this intent cannot be recreated or reused.');
                $existing=$row;
            }
            $changed=$existing===null;$writeStart=current_time('mysql',true);
            $id=(new Shop2HeldPreparationDispatcher($context))->enqueue($shop,$orderId,$intentId,$idempotencyKey);$this->requireResult($id);
            if(!is_int($id)||$id<1)throw new \RuntimeException('Held job storage did not return an exact identity.');
            $after=$this->inventory(true);$new=null;$oldById=[];foreach($before as $row)$oldById[(int)$row['id']]=$row;
            foreach($after as $row){if((int)$row['id']===$id)$new=$row;if(isset($oldById[(int)$row['id']])&&$oldById[(int)$row['id']]!==$row)throw new \RuntimeException('Existing queue material changed outside the requested enqueue.');}
            if(!is_array($new)||$new['payload']!==wp_json_encode($expected)||count($after)!==count($before)+($existing===null?1:0)||($existing!==null&&(int)$existing['id']!==$id))throw new \RuntimeException('The exact enqueue effect could not be confirmed.');
            if($existing===null&&($new['state']!=='BLOCKED'||$new['idempotency_key']!=='shop2-held:'.hash('sha256',$idempotencyKey)||(int)$new['max_attempts']!==3||$new['locked_by']!==null||$new['locked_at']!==null||$new['lease_expires_at']!==null||(int)$new['attempts']!==0||$new['next_attempt_at']!==null||$new['scheduled_at']!==null||$new['completed_at']!==null||$new['dead_lettered_at']!==null||$new['last_error']!==''||$new['created_at']<$writeStart||$new['created_at']>current_time('mysql',true)||$new['updated_at']!==$new['created_at']))throw new \RuntimeException('New local held work must have the exact repository defaults and remain BLOCKED.');
            $current=(new HeldFulfillmentPreparation($context))->prepare($shop,$orderId,$intentId);$this->requireResult($current);
            if(Validator::hash($prepared)!==Validator::hash($current))throw new \RuntimeException('Current preparation changed during enqueue.');
            if($existing===null&&!Logger::write('shop2_held_job_enqueued',['order_id'=>$orderId,'intent_id'=>$intentId,'payload_hash'=>hash('sha256',$new['payload']),'external_execution_authorized'=>false],'queue_job',(string)$id))throw new \RuntimeException('Held job audit persistence is unavailable.');
            $finalScope=$this->scope($shop,$orderId,$intentId,true);if(Validator::hash($scope)!==Validator::hash($finalScope))throw new \RuntimeException('Order or intent changed during enqueue.');
            $final=$this->load($finalScope,$id,true);if($final['row']!==$new)throw new \RuntimeException('The enqueued job changed before commit.');
            $this->requireResult($context->validate());$result=$this->view($final);$this->commit($shop,$actor);$committed=true;$this->admit($shop,$actor);return array_replace($result,self::flags(!$changed,$changed));
        }catch(\Throwable $e){return self::error($e->getMessage(),$committed,$changed,$this->commitState);}finally{
            $cleanupFailed=$started&&!$committed&&!$this->rollbackOriginal($changed);if($cleanupFailed)$this->commitState='UNKNOWN';
            if($context!==null){$released=$context->release($committed);if(is_wp_error($released)){if(($released->get_error_data()['transaction_outcome']??null)==='UNKNOWN')$this->commitState='UNKNOWN';return self::error('The retained photo read lease release is uncertain; inspect stored work without replay.',$committed,$changed,$this->commitState);}
                try{$this->admit($shop,$actor);}catch(\Throwable){return self::error('Current access changed after releasing the retained photo read lease.',$committed,$changed,$this->commitState);}
                $admitted=$context->finalAdmission($committed);if(is_wp_error($admitted))return self::error('Current retained reviewer authority changed after local enqueue.',$committed,$changed,$this->commitState);}
            if($cleanupFailed)return self::error('The original local rollback cannot be confirmed; inspect stored work without replay.',false,$changed,'UNKNOWN');
        }
    }

    public function quarantine(string $shop,int $orderId,int $intentId,int $jobId,string $expectedSnapshotHash):array|WP_Error
    {
        $this->commitState='NOT_ATTEMPTED';$started=false;$committed=false;$changed=false;
        try {
            $actor=get_current_user_id();$this->admit($shop,$actor);$this->fingerprint($expectedSnapshotHash);$this->begin();$started=true;$this->lockAdmission();
            $scope=$this->scope($shop,$orderId,$intentId,true);$before=$this->load($scope,$jobId,true);
            if(Validator::hash($before)!==$expectedSnapshotHash||!$this->eligible($before['row']))throw new \RuntimeException('Only the exact inspected expired RUNNING job can be quarantined.');
            $writeStart=current_time('mysql',true);
            $changed=true;if(!(new JobRepository())->recoverExpiredLease($jobId))throw new \RuntimeException('Expired-lease recovery did not establish a confirmed transition.');
            if(!Logger::write('shop2_held_job_quarantined',['order_id'=>$orderId,'intent_id'=>$intentId,'snapshot_hash'=>$expectedSnapshotHash,'retry_permitted'=>false],'queue_job',(string)$jobId))throw new \RuntimeException('Quarantine audit persistence is unavailable.');
            $finalScope=$this->scope($shop,$orderId,$intentId,true);$after=$this->load($finalScope,$jobId,true);
            $allowed=['state','last_error','locked_by','locked_at','lease_expires_at','updated_at'];
            if($scope!==$finalScope||$before['dependencies']!==$after['dependencies']||array_diff_key($before['row'],array_flip($allowed))!==array_diff_key($after['row'],array_flip($allowed))||$after['row']['state']!=='HUMAN_REVIEW'||$after['row']['last_error']!=='LEASE_EXPIRED'||$after['row']['locked_by']!==null||$after['row']['locked_at']!==null||$after['row']['lease_expires_at']!==null||$after['row']['updated_at']<$writeStart||$after['row']['updated_at']>current_time('mysql',true))throw new \RuntimeException('Queue changes exceeded the exact quarantine effect.');
            $result=$this->view($after);$this->commit($shop,$actor);$committed=true;$this->admit($shop,$actor);return array_replace($result,self::flags(false,true));
        }catch(\Throwable $e){return self::error($e->getMessage(),$committed,$changed,$this->commitState);}finally{if($started&&!$committed&&!$this->rollbackOriginal($changed))return self::error('The original local rollback cannot be confirmed; inspect stored work without replay.',false,$changed,'UNKNOWN');}
    }

    private function scope(string $shop,int $orderId,int $intentId,bool $lock=false,bool $admit=true):array
    {
        if($admit)$this->admit($shop,get_current_user_id());if($orderId<1||$intentId<1)throw new \RuntimeException('Exact stored order and intent identities are required.');
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $predicate=$lock&&!$admit?'':' AND '.EtsyStoredShopScope::orderPredicate($shop,'o');
        $orders=$this->rows($wpdb->prepare("SELECT o.* FROM ".Tables::orders()." o WHERE o.id=%d AND BINARY o.channel=BINARY 'etsy'".$predicate.$suffix,$orderId));
        $intents=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_intents().' WHERE id=%d AND order_id=%d'.$suffix,$intentId,$orderId));
        if(count($orders)!==1||count($intents)!==1||!in_array($intents[0]['intent_type'],['PREPARE_ORDER','PREPARE_FULFILLMENT'],true))throw new \RuntimeException('An exact current Shop 2 order and held intent are required.');
        $plans=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::fulfillment_plans().' WHERE id=%d AND order_id=%d'.$suffix,(int)$intents[0]['fulfillment_plan_id'],$orderId));
        if(count($plans)!==1||$intents[0]['environment']!==$orders[0]['environment']||$plans[0]['environment']!==$orders[0]['environment']||$plans[0]['payload_hash']!==$intents[0]['fulfillment_plan_payload_hash']||!hash_equals($plans[0]['payload_hash'],hash('sha256',$plans[0]['canonical_payload'])))throw new \RuntimeException('The stored intent and plan identity no longer agree.');
        return ['shop_key'=>$shop,'actor_id'=>get_current_user_id(),'order'=>$orders[0],'intent'=>$intents[0],'plan'=>$plans[0]];
    }

    private function load(array $scope,int $id,bool $lock=false):array
    {
        if($id<1)throw new \RuntimeException('An exact held job identity is required.');global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $rows=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::jobs().' WHERE id=%d'.$suffix,$id));if(count($rows)!==1)throw new \RuntimeException('Held job evidence is unavailable.');
        $row=$rows[0];$p=$this->decode($row);
        if(!$this->sameIntent($p,$scope)||$p['actor_id']!==$scope['actor_id']||$p['plan_payload_hash']!==$scope['plan']['payload_hash'])throw new \RuntimeException('Held job actor, order, intent or plan identity does not match.');
        $dependencies=[];foreach($p['_digiforge_dependencies']??[]as $dependency){$matches=$this->rows($wpdb->prepare('SELECT id,state FROM '.Tables::jobs().' WHERE id=%d'.$suffix,$dependency));if(count($matches)!==1)throw new \RuntimeException('Dependency evidence is unavailable.');$dependencies[$dependency]=$matches[0];}
        $policy=QueueDependencyPolicy::evaluate($id,$p['_digiforge_dependencies']??[],$dependencies);
        return ['scope'=>$scope,'row'=>$row,'dependencies'=>$dependencies,'dependency_policy'=>$policy];
    }

    private function decode(array $row):array
    {
        if(($row['job_type']??null)!==self::TYPE||!JobState::valid((string)($row['state']??''))||!is_string($row['payload']??null)||strlen($row['payload'])>8192)throw new \RuntimeException('Unclassifiable held work requires manual inspection before enqueue.');
        $p=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($p)||array_diff(array_keys($p),['schema','shop_key','actor_id','order_id','intent_id','plan_payload_hash','evidence_hash','_digiforge_dependencies'])!==[]||($p['schema']??null)!==1||($p['shop_key']??null)!=='personalized_pod')throw new \RuntimeException('Malformed held work cannot be treated as an empty queue.');
        foreach(['actor_id','order_id','intent_id']as $key)if(!is_int($p[$key]??null)||$p[$key]<1)throw new \RuntimeException('Held job identities are malformed.');
        foreach(['plan_payload_hash','evidence_hash']as $key)$this->fingerprint($p[$key]??'');
        $deps=array_key_exists('_digiforge_dependencies',$p)?$p['_digiforge_dependencies']:[];if(!is_array($deps)||!array_is_list($deps)||count($deps)>100||count(array_unique($deps,SORT_REGULAR))!==count($deps))throw new \RuntimeException('Held job dependencies are malformed.');foreach($deps as $dep)if(!is_int($dep)||$dep<1||$dep===(int)$row['id'])throw new \RuntimeException('Held job dependencies are malformed.');
        return $p;
    }

    private function inventory(bool $lock):array
    {
        global $wpdb;$rows=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::jobs().' WHERE job_type=%s ORDER BY id LIMIT %d'.($lock?' FOR UPDATE':''),self::TYPE,self::INVENTORY_LIMIT+1));
        if(count($rows)>self::INVENTORY_LIMIT)throw new \RuntimeException('Held work inventory exceeds the complete local inspection bound.');foreach($rows as $row)$this->decode($row);return $rows;
    }
    private function sameIntent(array $payload,array $scope):bool{return $payload['shop_key']===$scope['shop_key']&&$payload['order_id']===(int)$scope['order']['id']&&$payload['intent_id']===(int)$scope['intent']['id'];}
    private function eligible(array $row):bool{return $row['state']==='RUNNING'&&is_string($row['locked_by'])&&$row['locked_by']!==''&&is_string($row['lease_expires_at'])&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$row['lease_expires_at'])===1&&strtotime($row['lease_expires_at'].' UTC')!==false&&$row['lease_expires_at']<current_time('mysql',true);}
    private function view(array $source):array
    {
        $row=$source['row'];$job=array_intersect_key($row,array_flip(['id','state','attempts','max_attempts','last_error','next_attempt_at','locked_at','lease_expires_at','completed_at','dead_lettered_at','created_at','updated_at']));foreach(['id','attempts','max_attempts']as $key)$job[$key]=(int)$job[$key];
        $job['owner_hash']=is_string($row['locked_by'])?hash('sha256',$row['locked_by']):'';$job['payload_hash']=hash('sha256',$row['payload']);
        return ['job_id'=>(int)$row['id'],'shop_key'=>$source['scope']['shop_key'],'order_id'=>(int)$source['scope']['order']['id'],'intent_id'=>(int)$source['scope']['intent']['id'],'job'=>$job,'snapshot_hash'=>Validator::hash($source),'dependencies'=>$source['dependency_policy']+['count'=>count($source['dependencies'])],'quarantine_eligible'=>$this->eligible($row)]+self::flags();
    }
    private function admit(string $shop,int $actor):void
    {
        if($shop!=='personalized_pod'||$actor<1||get_current_user_id()!==$actor||!is_user_logged_in())throw new \RuntimeException('An exact current Shop 2 actor is required.');
        foreach(['manage_digiforge_automation','manage_digiforge_orders','manage_digiforge_pod']as $cap)if(ShopAccess::authorize($shop,$cap)!==true)throw new \RuntimeException('Current signed shop and module access is required.');
        if(get_current_user_id()!==$actor||!is_user_logged_in()||!current_user_can('manage_digiforge_automation')||!current_user_can('manage_digiforge_orders')||!current_user_can('manage_digiforge_pod'))throw new \RuntimeException('The current actor or module access changed.');
        if(get_current_user_id()!==$actor||!is_user_logged_in())throw new \RuntimeException('The current actor changed during final module admission.');
    }
    private function begin(bool $preparation=false,?PersonalizationReadContext $context=null):void
    {
        global $wpdb;$state=$this->rows('SELECT @@session.in_transaction AS active,@@session.autocommit AS autocommit');if(count($state)!==1||(string)$state[0]['active']!=='0'||(string)$state[0]['autocommit']!=='1')throw new \RuntimeException('A clean local transaction boundary is required.');
        $tables=[Tables::jobs(),Tables::orders(),Tables::fulfillment_plans(),Tables::fulfillment_intents(),Tables::audit_log(),$wpdb->options,$wpdb->users,$wpdb->usermeta];
        if($preparation)$tables=array_merge($tables,$this->preparationTables());
        foreach($tables as $table){$engines=$this->rows($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if(count($engines)!==1||strtoupper((string)$engines[0]['ENGINE'])!=='INNODB')throw new \RuntimeException('Verified transactional local storage is required.');}
        if($context!==null)$this->requireResult($context->validateBeforeTransaction());
        $database=$wpdb;$database->last_error='';$boundary=$database->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active,@@session.autocommit AS autocommit',ARRAY_A);
        if($wpdb!==$database||$database->last_error!==''||!is_array($boundary)||(string)($boundary['active']??'')!=='0'||(string)($boundary['autocommit']??'')!=='1'||!preg_match('/^[1-9][0-9]*$/D',(string)($boundary['connection_id']??'')))throw new \RuntimeException('An unchanged fresh autocommit boundary is required.');
        $this->transaction=(string)$boundary['connection_id'];$this->database=$database;
        if($database->query('START TRANSACTION')===false||$database->last_error!==''){$this->rollbackOriginal(false);throw new \RuntimeException('Local metadata transaction could not start.');}
        if(is_wp_error(NativeSourceTransaction::verify($database,$this->transaction))){$this->rollbackOriginal(false);throw new \RuntimeException('A durable local metadata transaction is required.');}
        if($context!==null){$valid=$context->validate();if(is_wp_error($valid)){if(!$this->rollbackOriginal(false))$this->commitState='UNKNOWN';$this->requireResult($valid);}}
    }
    private string $transaction='';
    private string $commitState='NOT_ATTEMPTED';
    private ?object $database=null;
    private function preparationTables():array
    {
        return [Tables::order_line_items(),Tables::pod_mappings(),Tables::pod_business_mappings(),Tables::businesses(),Tables::stores(),Tables::product_programs(),Tables::personalization_schemas(),Tables::personalization_submissions(),Tables::integrations(),Tables::integration_secrets(),Tables::pod_render_evidence(),Tables::listings(),Tables::listing_pod_bindings(),Tables::digital_products(),Tables::pod_print_areas(),Tables::pod_cost_snapshots(),Tables::production_plans(),Tables::release_bundles(),Tables::etsy_draft_packages(),$GLOBALS['wpdb']->prefix.'digiforge_etsy_operations',\DigiForge\Database\ProviderConnectionSchema::table()];
    }
    /** Lock actual local ACL/session records before creating the transaction read view. */
    private function lockAdmission():void
    {
        global $wpdb;$predicates=[];$args=[];
        foreach(['digiforge_shop_access_','digiforge_portal_','digiforge_local_review_']as $prefix){$predicates[]='option_name LIKE %s';$args[]=$wpdb->esc_like($prefix).'%';}
        $predicates[]='option_name=%s';$args[]=$wpdb->prefix.'user_roles';
        $options=$this->bounded($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE '.implode(' OR ',$predicates).' ORDER BY option_name LIMIT 1001 FOR UPDATE',...$args),1000);
        $actors=[get_current_user_id()];foreach($options as $option){if(!str_starts_with($option['option_name'],'digiforge_shop_access_state_'))continue;$state=json_decode($option['option_value'],true);if(!is_array($state))continue;foreach($state['grants']??[]as $grant)if(is_array($grant)&&is_int($grant['grantor_id']??null)&&$grant['grantor_id']>0)$actors[]=$grant['grantor_id'];}
        $actors=array_values(array_unique($actors));if(count($actors)>100)throw new \RuntimeException('Current actor admission exceeds the local lock bound.');$marks=implode(',',array_fill(0,count($actors),'%d'));
        $this->bounded($wpdb->prepare('SELECT ID FROM '.$wpdb->users.' WHERE ID IN ('.$marks.') ORDER BY ID FOR UPDATE',...$actors),100);
        $this->bounded($wpdb->prepare('SELECT umeta_id FROM '.$wpdb->usermeta.' WHERE user_id IN ('.$marks.') ORDER BY umeta_id LIMIT 1001 FOR UPDATE',...$actors),1000);
    }
    /** Current locking reads precede all ordinary Held preparation reads, including empty insertion ranges. */
    private function lockPreparationSource(array $scope):void
    {
        global $wpdb;$order=(int)$scope['order']['id'];$environment=$scope['order']['environment'];
        $integrations=$this->bounded($wpdb->prepare("SELECT * FROM ".Tables::integrations()." WHERE provider IN ('printify','etsy') AND environment=%s ORDER BY id LIMIT 101 FOR UPDATE",$environment));
        foreach($integrations as $integration){$this->bounded($wpdb->prepare('SELECT id FROM '.Tables::integration_secrets().' WHERE integration_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$integration['id']));$this->bounded($wpdb->prepare('SELECT * FROM '.\DigiForge\Database\ProviderConnectionSchema::table().' WHERE integration_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$integration['id']));}
        $lines=$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$order));
        foreach(['fulfillment_plans','fulfillment_intents','pod_render_evidence']as $table)$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE order_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$order));
        foreach($lines as $line){
            $version=(int)$line['product_version_id'];$mapping=(int)$line['provider_mapping_id'];$listing=(int)$line['listing_id'];
            $maps=$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d FOR UPDATE',$mapping));
            $owners=$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$mapping));
            foreach($owners as $owner)foreach(['businesses'=>'business_id','stores'=>'store_id','product_programs'=>'product_program_id']as $table=>$column)$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE id=%d FOR UPDATE',(int)$owner[$column]));
            foreach(['personalization_schemas','digital_products']as $table)$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE product_version_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$version));
            $this->bounded($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE order_line_item_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$line['id']));
            foreach(['pod_print_areas','pod_cost_snapshots']as $table)$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::{$table}().' WHERE provider_mapping_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$mapping));
            foreach($maps as $map){$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::production_plans().' WHERE id=%d FOR UPDATE',(int)$map['production_plan_id']));$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::release_bundles().' WHERE production_plan_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$map['production_plan_id']));}
            $this->bounded($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d FOR UPDATE',$listing));$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::listing_pod_bindings().' WHERE listing_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$listing));
            $packages=$this->bounded($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE listing_id=%d ORDER BY id LIMIT 101 FOR UPDATE',$listing));foreach($packages as $package)$this->bounded($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'digiforge_etsy_operations WHERE draft_package_id=%d ORDER BY id LIMIT 101 FOR UPDATE',(int)$package['id']));
        }
    }
    private function bounded(string $sql,int $limit=100):array{$rows=$this->rows($sql);if(count($rows)>$limit)throw new \RuntimeException('Current source exceeds the complete local lock bound.');return $rows;}
    private function commit(string $shop,int $actor):void
    {
        if($this->database===null||is_wp_error(NativeSourceTransaction::verify($this->database,$this->transaction)))throw new \RuntimeException('Local metadata transaction changed; inspect stored work before retry.');
        $this->admit($shop,$actor);
        if(is_wp_error(NativeSourceTransaction::verify($this->database,$this->transaction)))throw new \RuntimeException('Local metadata transaction changed during final admission.');
        global $wpdb;$database=$this->database;$database->last_error='';$this->commitState='UNKNOWN';if($database->query('COMMIT')===false||$database->last_error!=='')throw new \RuntimeException('Local commit acknowledgement is uncertain; inspect stored work without replay.');
        $database->last_error='';$confirmed=$database->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active,@@session.autocommit AS autocommit',ARRAY_A);
        if($wpdb!==$database||$database->last_error!==''||!is_array($confirmed)||(string)($confirmed['connection_id']??'')!==$this->transaction||(string)($confirmed['active']??'')!=='0'||(string)($confirmed['autocommit']??'')!=='1')throw new \RuntimeException('The original committed session cannot be confirmed; inspect stored work without replay.');$this->commitState='COMMITTED';
    }
    /** Never roll back a replacement object or a reconnected session. */
    private function rollbackOriginal(bool $changed):bool
    {
        $database=$this->database;if($database===null)return false;
        $database->last_error='';$state=$database->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active',ARRAY_A);
        if($database->last_error!==''||!is_array($state)||(string)($state['connection_id']??'')!==$this->transaction)return false;
        if((string)($state['active']??'')==='0')return $this->commitState==='UNKNOWN'||!$changed;
        if((string)($state['active']??'')!=='1'||$database->query('ROLLBACK')===false)return false;
        $database->last_error='';$after=$database->get_row('SELECT CONNECTION_ID() AS connection_id,@@session.in_transaction AS active',ARRAY_A);
        return $database->last_error===''&&is_array($after)&&(string)($after['connection_id']??'')===$this->transaction&&(string)($after['active']??'')==='0';
    }
    private function rows(string $sql):array{global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||$wpdb->last_error!=='')throw new \RuntimeException('Current local metadata evidence is unavailable.');return $rows;}
    private function fingerprint(mixed $hash):void{if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \RuntimeException('An exact server-derived source fingerprint is required.');}
    private function requireResult(mixed $result):void{if(is_wp_error($result))throw new \RuntimeException('Current held preparation or queue persistence is unavailable.');}
    private static function flags(bool $readOnly=true,bool $changed=false):array{return ['read_only'=>$readOnly,'local_metadata_changed'=>$changed,'release_hold'=>true,'retry_permitted'=>false,'replay_permitted'=>false,'dispatch_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];}
    private static function error(string $message,bool $committed=false,bool $changed=false,string $commitState='NOT_ATTEMPTED'):WP_Error{return new WP_Error('shop2_held_job_unavailable',$message,['status'=>409,'local_commit_state'=>$commitState,'local_metadata_committed'=>$commitState==='UNKNOWN'?null:$committed,'local_metadata_changed'=>$commitState==='UNKNOWN'&&$changed?null:($committed&&$changed),'persisted_effect_possible'=>$changed]+self::flags(!$changed,$committed&&$changed));}
}
