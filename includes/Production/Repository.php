<?php

declare(strict_types=1);

namespace DigiForge\Production;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function __construct(private ?Validator $validator = null) { $this->validator ??= new Validator(); }

    public function createSpec(array $input, ?string $key = null): array|WP_Error
    {
        $pv=absint($input['product_version_id']??0);
        if($pv<1||!$this->exists(Tables::product_versions(),$pv)) return $this->error('invalid_parent','A valid product_version_id is required.');
        $content=$this->validator->boundedStructured((array)($input['content_requirements']??[])); if(is_wp_error($content))return $content;
        $design=$this->validator->boundedStructured((array)($input['design_constraints']??[])); if(is_wp_error($design))return $design;
        $w=absint($input['width_px']??0);$h=absint($input['height_px']??0);$dpi=absint($input['dpi']??0);if($e=$this->validator->dimensions($w,$h,$dpi))return $e;
        $assetKey=sanitize_key((string)($input['asset_key']??''));$assetType=sanitize_key((string)($input['asset_type']??''));$format=sanitize_key((string)($input['format']??''));
        if($assetKey===''||$assetType===''||$format==='')return $this->error('validation','asset_key, asset_type and format are required.');
        return $this->insert(Tables::asset_specs(),$key,[
            'product_version_id'=>$pv,'digital_product_id'=>absint($input['digital_product_id']??0),'asset_key'=>$assetKey,'asset_type'=>$assetType,
            'purpose'=>sanitize_text_field((string)($input['purpose']??'')),'format'=>$format,'width_px'=>$w,'height_px'=>$h,'dpi'=>$dpi,
            'color_space'=>sanitize_key((string)($input['color_space']??'')),'orientation'=>sanitize_key((string)($input['orientation']??'')),
            'variant_key'=>sanitize_key((string)($input['variant_key']??'')),'locale'=>sanitize_text_field((string)($input['locale']??'')),
            'content_requirements'=>wp_json_encode($content),'design_constraints'=>wp_json_encode($design),'source_policy'=>'local_only','state'=>'DRAFT',
            'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'asset_spec');
    }

    public function createPlan(array $input, ?string $key = null): array|WP_Error
    {
        $pv=absint($input['product_version_id']??0);if($pv<1||!$this->exists(Tables::product_versions(),$pv))return $this->error('invalid_parent','A valid product_version_id is required.');
        $planKey=sanitize_key((string)($input['plan_key']??''));$version=sanitize_text_field((string)($input['version_label']??''));$channel=sanitize_key((string)($input['channel']??''));$type=sanitize_key((string)($input['production_type']??''));
        if($planKey===''||$version===''||!in_array($channel,['digital','pod','hybrid'],true)||$type==='')return $this->error('validation','Invalid production plan fields.');
        return $this->insert(Tables::production_plans(),$key,[
            'product_version_id'=>$pv,'plan_key'=>$planKey,'version_label'=>$version,'channel'=>$channel,'production_type'=>$type,
            'requirements_version'=>sanitize_text_field((string)($input['requirements_version']??'v1')),'state'=>'DRAFT','notes'=>sanitize_textarea_field((string)($input['notes']??'')),
            'approved_by'=>0,'approved_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'production_plan');
    }

    public function linkAsset(int $planId,int $specId,bool $required=true,int $sequence=0): bool|WP_Error
    {
        $plan=$this->find(Tables::production_plans(),$planId);$spec=$this->find(Tables::asset_specs(),$specId);
        if($plan===null||$spec===null)return $this->error('not_found','Production plan or asset specification not found.',404);
        if((int)$plan['product_version_id']!==(int)$spec['product_version_id'])return $this->error('invalid_relationship','Plan and asset specification must belong to the same product version.',409);
        global $wpdb;$ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.Tables::production_plan_assets().' (production_plan_id,asset_spec_id,is_required,sequence_no,created_at) VALUES (%d,%d,%d,%d,%s)',$planId,$specId,$required?1:0,max(0,$sequence),$this->now()));
        if($ok===false)return $this->error('link_failed','Unable to link production asset.',500);Logger::audit('production_asset_linked',['asset_spec_id'=>$specId,'required'=>$required],'production_plan',(string)$planId);return true;
    }

    public function createIntent(array $input, ?string $key=null): array|WP_Error
    {
        $planId=absint($input['production_plan_id']??0);$specId=absint($input['asset_spec_id']??0);$plan=$this->find(Tables::production_plans(),$planId);$spec=$this->find(Tables::asset_specs(),$specId);
        if($plan===null||$spec===null||(int)$plan['product_version_id']!==(int)$spec['product_version_id'])return $this->error('invalid_parent','Plan and asset specification must exist and share a product version.');
        $intent=strtoupper(sanitize_key((string)($input['intent_type']??'')));$provider=sanitize_key((string)($input['provider_class']??'internal'));
        if(!in_array($intent,['DESIGN','RENDER','EXPORT','PACKAGE','PREVIEW','COPY','LOCAL_TRANSFORM'],true)||!in_array($provider,['internal','ai','canva','manual','future_provider'],true))return $this->error('validation','Invalid production intent.');
        $payload=$this->validator->boundedStructured((array)($input['input_payload']??[]));if(is_wp_error($payload))return $payload;
        return $this->insert(Tables::production_intents(),$key,['production_plan_id'=>$planId,'asset_spec_id'=>$specId,'intent_type'=>$intent,'provider_class'=>$provider,
            'requested_capability'=>sanitize_key((string)($input['requested_capability']??'')),'input_contract_version'=>sanitize_text_field((string)($input['input_contract_version']??'v1')),
            'input_payload'=>wp_json_encode($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()], 'production_intent');
    }

    public function addRevision(array $input, ?string $key=null): array|WP_Error
    {
        $spec=absint($input['asset_spec_id']??0);if(!$this->exists(Tables::asset_specs(),$spec))return $this->error('invalid_parent','Valid asset_spec_id is required.');
        $checksum=strtolower(sanitize_text_field((string)($input['checksum_sha256']??'')));$storage=sanitize_text_field((string)($input['storage_reference']??''));if(!$this->validator->checksum($checksum)||!$this->validator->storageReference($storage))return $this->error('validation','Invalid checksum or local storage reference.');
        $w=absint($input['width_px']??0);$h=absint($input['height_px']??0);if($e=$this->validator->dimensions($w,$h,0))return $e;
        $prov=$this->validator->boundedStructured((array)($input['provenance']??[]));if(is_wp_error($prov))return $prov;$label=sanitize_text_field((string)($input['revision_label']??''));if($label==='')return $this->error('validation','revision_label is required.');
        return $this->insert(Tables::asset_revisions(),$key,['asset_spec_id'=>$spec,'revision_label'=>$label,'storage_reference'=>$storage,'checksum_sha256'=>$checksum,
            'mime_type'=>sanitize_text_field((string)($input['mime_type']??'')),'byte_size'=>absint($input['byte_size']??0),'width_px'=>$w,'height_px'=>$h,'provenance'=>wp_json_encode($prov),
            'state'=>'PENDING_QA','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()], 'asset_revision');
    }

    public function addQa(array $input, ?string $key=null): array|WP_Error
    {
        $type=sanitize_key((string)($input['target_type']??''));$id=absint($input['target_id']??0);$check=sanitize_key((string)($input['check_type']??''));$status=strtoupper(sanitize_key((string)($input['status']??'PENDING')));
        $targets=['revision'=>Tables::asset_revisions(),'spec'=>Tables::asset_specs(),'plan'=>Tables::production_plans(),'bundle'=>Tables::release_bundles()];
        if(!isset($targets[$type])||$id<1||!$this->exists($targets[$type],$id)||$check===''||!in_array($status,['PENDING','PASS','FAIL','WAIVED'],true))return $this->error('validation','Invalid QA target or fields.');
        $details=$this->validator->boundedStructured((array)($input['details']??[]));if(is_wp_error($details))return $details;$reviewer=0;$reviewedAt=null;$reason='';
        if($status==='WAIVED'){$reason=sanitize_text_field((string)($input['waiver_reason']??''));$reviewer=get_current_user_id();if($reason===''||$reviewer<1)return $this->error('authorization','WAIVED requires an authenticated reviewer and reason.',403);$reviewedAt=$this->now();}
        return $this->insert(Tables::production_qa(),$key,['target_type'=>$type,'target_id'=>$id,'check_type'=>$check,'check_version'=>sanitize_text_field((string)($input['check_version']??'v1')),
            'status'=>$status,'details'=>wp_json_encode($details),'waiver_reason'=>$reason,'reviewed_by'=>$reviewer,'reviewed_at'=>$reviewedAt,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()], 'production_qa');
    }

    public function createBundle(array $input, ?string $key=null): array|WP_Error
    {
        $plan=absint($input['production_plan_id']??0);if(!$this->exists(Tables::production_plans(),$plan))return $this->error('invalid_parent','Valid production_plan_id is required.');$bundle=sanitize_key((string)($input['bundle_key']??''));$version=sanitize_text_field((string)($input['version_label']??''));if($bundle===''||$version==='')return $this->error('validation','bundle_key and version_label are required.');
        return $this->insert(Tables::release_bundles(),$key,['production_plan_id'=>$plan,'bundle_key'=>$bundle,'version_label'=>$version,'manifest'=>'[]','checksum_sha256'=>'','state'=>'DRAFT','approved_by'=>0,'approved_at'=>null,'readiness'=>'{}','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()], 'release_bundle');
    }

    public function validateBundle(int $bundleId): array|WP_Error
    {
        $bundle=$this->find(Tables::release_bundles(),$bundleId);if($bundle===null)return $this->error('not_found','Bundle not found.',404);$plan=$this->find(Tables::production_plans(),(int)$bundle['production_plan_id']);if($plan===null)return $this->error('invalid_parent','Production plan not found.',409);
        global $wpdb;$required=$wpdb->get_results($wpdb->prepare('SELECT asset_spec_id FROM '.Tables::production_plan_assets().' WHERE production_plan_id=%d AND is_required=1 ORDER BY sequence_no ASC,asset_spec_id ASC',(int)$plan['id']),ARRAY_A)?:[];
        $manifest=[];$missing=[];$qaBlockers=[];
        foreach($required as $link){$specId=(int)$link['asset_spec_id'];$rev=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Tables::asset_revisions()." WHERE asset_spec_id=%d AND state='APPROVED' ORDER BY id DESC LIMIT 1",$specId),ARRAY_A);if(!is_array($rev)){$missing[]=$specId;continue;}$qaTotal=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::production_qa()." WHERE target_type='revision' AND target_id=%d",(int)$rev['id']));$qaBad=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::production_qa()." WHERE target_type='revision' AND target_id=%d AND status NOT IN ('PASS','WAIVED')",(int)$rev['id']));if($qaTotal<1||$qaBad>0){$qaBlockers[]=(int)$rev['id'];continue;}$manifest[]=['asset_spec_id'=>$specId,'asset_revision_id'=>(int)$rev['id'],'checksum_sha256'=>(string)$rev['checksum_sha256']];}
        $humanApproved=(int)($bundle['approved_by']??0)>0;$planApproved=($plan['state']??'')==='APPROVED';$ready=$planApproved&&$required!==[]&&$missing===[]&&$qaBlockers===[]&&$humanApproved;
        $manifestJson=$this->validator->canonicalJson($manifest);$checksum=hash('sha256',$manifestJson);$readiness=['ready'=>$ready,'missing_assets'=>$missing,'qa_blockers'=>$qaBlockers,'human_approved'=>$humanApproved,'plan_approved'=>$planApproved,'required_asset_count'=>count($required)];
        $next=$ready?'RELEASE_READY':((string)$bundle['state']==='APPROVED'?'APPROVED':'REVIEW_REQUIRED');$wpdb->update(Tables::release_bundles(),['manifest'=>$manifestJson,'checksum_sha256'=>$checksum,'readiness'=>wp_json_encode($readiness),'state'=>$next,'updated_at'=>$this->now()],['id'=>$bundleId]);Logger::audit('release_bundle_validated',$readiness,'release_bundle',(string)$bundleId);return $this->find(Tables::release_bundles(),$bundleId)??$this->error('not_found','Bundle not found.',404);
    }

    public function transition(string $entity,int $id,string $to): array|WP_Error
    {
        $map=['spec'=>[Tables::asset_specs(),'spec'],'plan'=>[Tables::production_plans(),'plan'],'intent'=>[Tables::production_intents(),'intent'],'revision'=>[Tables::asset_revisions(),'revision'],'bundle'=>[Tables::release_bundles(),'bundle']];if(!isset($map[$entity]))return $this->error('validation','Unknown production entity.');[$table,$kind]=$map[$entity];$row=$this->find($table,$id);if($row===null)return $this->error('not_found','Production record not found.',404);$from=(string)$row['state'];$to=strtoupper(sanitize_key($to));
        if($kind==='bundle'&&$to==='RELEASE_READY')return $this->error('readiness_validation_required','Bundle RELEASE_READY is set only by deterministic bundle validation.',409);
        if(!Lifecycle::can($kind,$from,$to))return $this->error('invalid_transition','Illegal production state transition.',409);$data=['state'=>$to,'updated_at'=>$this->now()];if($to==='APPROVED'&&in_array($kind,['plan','bundle'],true)){$uid=get_current_user_id();if($uid<1)return $this->error('authorization','Approval requires an authenticated reviewer.',403);$data['approved_by']=$uid;$data['approved_at']=$this->now();}
        global $wpdb;$updated=$wpdb->update($table,$data,['id'=>$id,'state'=>$from]);if($updated===false)return $this->error('update_failed','Unable to update production state.',500);if($updated===0)return $this->error('state_conflict','Production state changed concurrently.',409);Logger::audit('production_state_changed',['entity'=>$entity,'from'=>$from,'to'=>$to],$entity,(string)$id);return $this->find($table,$id)??$this->error('not_found','Production record not found.',404);
    }

    public function list(string $entity,int $page=1,int $perPage=20):array
    {
        $map=['specs'=>Tables::asset_specs(),'plans'=>Tables::production_plans(),'intents'=>Tables::production_intents(),'revisions'=>Tables::asset_revisions(),'qa'=>Tables::production_qa(),'bundles'=>Tables::release_bundles()];if(!isset($map[$entity]))return['items'=>[],'pagination'=>['page'=>1,'per_page'=>20,'total_items'=>0,'total_pages'=>0]];global $wpdb;$page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;$table=$map[$entity];$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT %d OFFSET %d',$perPage,$offset),ARRAY_A)?:[];$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$table);return['items'=>array_map([$this,'normalize'],$rows),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total_items'=>$total,'total_pages'=>(int)ceil($total/$perPage)]];
    }

    private function insert(string $table,?string $key,array $data,string $type):array|WP_Error
    {
        global $wpdb;$key=$key===null?null:sanitize_text_field($key);if($key==='')$key=null;if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key too long.');if($key!==null){$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE idempotency_key=%s',$key),ARRAY_A);if(is_array($existing))return $this->normalize($existing)+['idempotent_replay'=>true];$data['idempotency_key']=$key;}if(!$wpdb->insert($table,$data))return $this->error('create_failed','Unable to create production record.',500);Logger::audit($type.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$type,(string)$wpdb->insert_id);return $this->find($table,(int)$wpdb->insert_id)??$this->error('create_failed','Unable to read production record.',500);
    }
    private function exists(string $table,int $id):bool{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE id=%d',$id))>0;}
    private function find(string $table,int $id):?array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);return is_array($r)?$this->normalize($r):null;}
    public function normalize(array $row):array{unset($row['idempotency_key']);foreach(['content_requirements','design_constraints','input_payload','provenance','details','manifest','readiness'] as $k){if(isset($row[$k])&&is_string($row[$k])){$d=json_decode($row[$k],true);if(is_array($d))$row[$k]=$d;}}return $row;}
    private function now():string{return current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400):WP_Error{return new WP_Error('digiforge_'.$code,__($message,'digiforge'),['status'=>$status]);}
}
