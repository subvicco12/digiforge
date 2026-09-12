<?php

declare(strict_types=1);

namespace DigiForge\Production;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function __construct(private ?Validator $validator = null)
    {
        $this->validator ??= new Validator();
    }

    /** @return array<string,mixed>|WP_Error */
    public function createSpec(array $input, ?string $key = null)
    {
        $productVersionId = absint($input['product_version_id'] ?? 0);
        if ($productVersionId < 1 || ! $this->exists(Tables::product_versions(), $productVersionId)) {
            return $this->error('invalid_parent', 'A valid product_version_id is required.');
        }
        $structured = $this->structuredPair($input);
        if (is_wp_error($structured)) return $structured;
        $width = absint($input['width_px'] ?? 0); $height = absint($input['height_px'] ?? 0); $dpi = absint($input['dpi'] ?? 0);
        if ($e = $this->validator->dimensions($width, $height, $dpi)) return $e;
        $assetKey = sanitize_key((string)($input['asset_key'] ?? ''));
        $assetType = sanitize_key((string)($input['asset_type'] ?? ''));
        $format = sanitize_key((string)($input['format'] ?? ''));
        if ($assetKey === '' || $assetType === '' || $format === '') return $this->error('validation','asset_key, asset_type and format are required.');
        return $this->insert(Tables::asset_specs(), $key, [
            'product_version_id'=>$productVersionId,'digital_product_id'=>absint($input['digital_product_id'] ?? 0),
            'asset_key'=>$assetKey,'asset_type'=>$assetType,'purpose'=>sanitize_text_field((string)($input['purpose'] ?? '')),
            'format'=>$format,'width_px'=>$width,'height_px'=>$height,'dpi'=>$dpi,
            'color_space'=>sanitize_key((string)($input['color_space'] ?? '')),'orientation'=>sanitize_key((string)($input['orientation'] ?? '')),
            'variant_key'=>sanitize_key((string)($input['variant_key'] ?? '')),'locale'=>sanitize_text_field((string)($input['locale'] ?? '')),
            'content_requirements'=>wp_json_encode($structured['content_requirements']),'design_constraints'=>wp_json_encode($structured['design_constraints']),
            'source_policy'=>'local_only','state'=>'DRAFT','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ], 'asset_spec');
    }

    /** @return array<string,mixed>|WP_Error */
    public function createPlan(array $input, ?string $key = null)
    {
        $productVersionId = absint($input['product_version_id'] ?? 0);
        if ($productVersionId < 1 || ! $this->exists(Tables::product_versions(), $productVersionId)) return $this->error('invalid_parent','A valid product_version_id is required.');
        $planKey=sanitize_key((string)($input['plan_key']??'')); $version=sanitize_text_field((string)($input['version_label']??''));
        $channel=sanitize_key((string)($input['channel']??'')); $type=sanitize_key((string)($input['production_type']??''));
        if($planKey===''||$version===''||!in_array($channel,['digital','pod','hybrid'],true)||$type==='') return $this->error('validation','Invalid production plan fields.');
        return $this->insert(Tables::production_plans(),$key,[
            'product_version_id'=>$productVersionId,'plan_key'=>$planKey,'version_label'=>$version,'channel'=>$channel,'production_type'=>$type,
            'requirements_version'=>sanitize_text_field((string)($input['requirements_version']??'v1')),'state'=>'DRAFT','notes'=>sanitize_textarea_field((string)($input['notes']??'')),
            'approved_by'=>0,'approved_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'production_plan');
    }

    public function linkAsset(int $planId, int $specId, bool $required = true, int $sequence = 0): bool|WP_Error
    {
        if(!$this->exists(Tables::production_plans(),$planId)||!$this->exists(Tables::asset_specs(),$specId)) return $this->error('not_found','Production plan or asset specification not found.',404);
        global $wpdb;
        $ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.Tables::production_plan_assets().' (production_plan_id,asset_spec_id,is_required,sequence_no,created_at) VALUES (%d,%d,%d,%d,%s)',$planId,$specId,$required?1:0,max(0,$sequence),$this->now()));
        if($ok===false) return $this->error('link_failed','Unable to link production asset.',500);
        Logger::audit('production_asset_linked',['asset_spec_id'=>$specId,'required'=>$required],'production_plan',(string)$planId);
        return true;
    }

    /** @return array<string,mixed>|WP_Error */
    public function createIntent(array $input, ?string $key = null)
    {
        $plan=absint($input['production_plan_id']??0); $spec=absint($input['asset_spec_id']??0);
        if(!$this->exists(Tables::production_plans(),$plan)||!$this->exists(Tables::asset_specs(),$spec)) return $this->error('invalid_parent','Valid production_plan_id and asset_spec_id are required.');
        $intent=strtoupper(sanitize_key((string)($input['intent_type']??''))); $provider=sanitize_key((string)($input['provider_class']??'internal'));
        if(!in_array($intent,['DESIGN','RENDER','EXPORT','PACKAGE','PREVIEW','COPY','LOCAL_TRANSFORM'],true)) return $this->error('validation','Invalid intent type.');
        if(!in_array($provider,['internal','ai','canva','manual','future_provider'],true)) return $this->error('validation','Invalid provider class.');
        $payload=$this->validator->boundedStructured((array)($input['input_payload']??[])); if(is_wp_error($payload)) return $payload;
        return $this->insert(Tables::production_intents(),$key,[
            'production_plan_id'=>$plan,'asset_spec_id'=>$spec,'intent_type'=>$intent,'provider_class'=>$provider,
            'requested_capability'=>sanitize_key((string)($input['requested_capability']??'')),'input_contract_version'=>sanitize_text_field((string)($input['input_contract_version']??'v1')),
            'input_payload'=>wp_json_encode($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'production_intent');
    }

    /** @return array<string,mixed>|WP_Error */
    public function addRevision(array $input, ?string $key = null)
    {
        $spec=absint($input['asset_spec_id']??0); if(!$this->exists(Tables::asset_specs(),$spec)) return $this->error('invalid_parent','Valid asset_spec_id is required.');
        $checksum=strtolower(sanitize_text_field((string)($input['checksum_sha256']??''))); $storage=sanitize_text_field((string)($input['storage_reference']??''));
        if(!$this->validator->checksum($checksum)||!$this->validator->storageReference($storage)) return $this->error('validation','Invalid checksum or local storage reference.');
        $prov=$this->validator->boundedStructured((array)($input['provenance']??[])); if(is_wp_error($prov)) return $prov;
        $label=sanitize_text_field((string)($input['revision_label']??'')); if($label==='') return $this->error('validation','revision_label is required.');
        return $this->insert(Tables::asset_revisions(),$key,[
            'asset_spec_id'=>$spec,'revision_label'=>$label,'storage_reference'=>$storage,'checksum_sha256'=>$checksum,'mime_type'=>sanitize_text_field((string)($input['mime_type']??'')),
            'byte_size'=>absint($input['byte_size']??0),'width_px'=>absint($input['width_px']??0),'height_px'=>absint($input['height_px']??0),'provenance'=>wp_json_encode($prov),
            'state'=>'PENDING_QA','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'asset_revision');
    }

    /** @return array<string,mixed>|WP_Error */
    public function addQa(array $input, ?string $key = null)
    {
        $targetType=sanitize_key((string)($input['target_type']??'')); $targetId=absint($input['target_id']??0); $check=sanitize_key((string)($input['check_type']??''));
        $status=strtoupper(sanitize_key((string)($input['status']??'PENDING')));
        if($targetType===''||$targetId<1||$check===''||!in_array($status,['PENDING','PASS','FAIL','WAIVED'],true)) return $this->error('validation','Invalid QA fields.');
        $details=$this->validator->boundedStructured((array)($input['details']??[])); if(is_wp_error($details)) return $details;
        $reviewer=0;$reviewedAt=null;$reason='';
        if($status==='WAIVED'){ $reason=sanitize_text_field((string)($input['waiver_reason']??'')); if($reason==='') return $this->error('validation','WAIVED requires a reason.'); $reviewer=get_current_user_id(); if($reviewer<1)return $this->error('authorization','WAIVED requires an authenticated reviewer.',403); $reviewedAt=$this->now(); }
        return $this->insert(Tables::production_qa(),$key,[
            'target_type'=>$targetType,'target_id'=>$targetId,'check_type'=>$check,'check_version'=>sanitize_text_field((string)($input['check_version']??'v1')),'status'=>$status,
            'details'=>wp_json_encode($details),'waiver_reason'=>$reason,'reviewed_by'=>$reviewer,'reviewed_at'=>$reviewedAt,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'production_qa');
    }

    /** @return array<string,mixed>|WP_Error */
    public function createBundle(array $input, ?string $key = null)
    {
        $plan=absint($input['production_plan_id']??0); if(!$this->exists(Tables::production_plans(),$plan)) return $this->error('invalid_parent','Valid production_plan_id is required.');
        $bundle=sanitize_key((string)($input['bundle_key']??''));$version=sanitize_text_field((string)($input['version_label']??'')); if($bundle===''||$version==='') return $this->error('validation','bundle_key and version_label are required.');
        return $this->insert(Tables::release_bundles(),$key,[
            'production_plan_id'=>$plan,'bundle_key'=>$bundle,'version_label'=>$version,'manifest'=>'[]','checksum_sha256'=>'','state'=>'DRAFT','approved_by'=>0,'approved_at'=>null,'readiness'=>'{}',
            'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'release_bundle');
    }

    /** @return array<string,mixed>|WP_Error */
    public function validateBundle(int $bundleId)
    {
        $bundle=$this->find(Tables::release_bundles(),$bundleId); if($bundle===null)return $this->error('not_found','Bundle not found.',404);
        $plan=$this->find(Tables::production_plans(),(int)$bundle['production_plan_id']); if($plan===null)return $this->error('invalid_parent','Production plan not found.',409);
        global $wpdb;
        $required=$wpdb->get_results($wpdb->prepare('SELECT asset_spec_id FROM '.Tables::production_plan_assets().' WHERE production_plan_id=%d AND is_required=1 ORDER BY sequence_no ASC,asset_spec_id ASC',(int)$plan['id']),ARRAY_A) ?: [];
        $manifest=[];$missing=[];$qaFailures=[];
        foreach($required as $link){$specId=(int)$link['asset_spec_id'];$rev=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Tables::asset_revisions()." WHERE asset_spec_id=%d AND state='APPROVED' ORDER BY id DESC LIMIT 1",$specId),ARRAY_A);if(!is_array($rev)){$missing[]=$specId;continue;}$bad=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::production_qa()." WHERE target_type='revision' AND target_id=%d AND status IN ('PENDING','FAIL')",(int)$rev['id']));if($bad>0){$qaFailures[]=(int)$rev['id'];continue;}$manifest[]=['asset_spec_id'=>$specId,'asset_revision_id'=>(int)$rev['id'],'checksum_sha256'=>(string)$rev['checksum_sha256']];}
        $ready=($plan['state']??'')==='APPROVED'&&$missing===[]&&$qaFailures===[]&&(int)($bundle['approved_by']??0)>0;
        $manifestJson=$this->validator->canonicalJson($manifest);$checksum=hash('sha256',$manifestJson);$readiness=['ready'=>$ready,'missing_assets'=>$missing,'qa_blockers'=>$qaFailures,'human_approved'=>(int)($bundle['approved_by']??0)>0,'plan_approved'=>($plan['state']??'')==='APPROVED'];
        $wpdb->update(Tables::release_bundles(),['manifest'=>$manifestJson,'checksum_sha256'=>$checksum,'readiness'=>wp_json_encode($readiness),'state'=>$ready?'RELEASE_READY':'REVIEW_REQUIRED','updated_at'=>$this->now()],['id'=>$bundleId]);
        Logger::audit('release_bundle_validated',$readiness,'release_bundle',(string)$bundleId);
        return $this->find(Tables::release_bundles(),$bundleId) ?? $this->error('not_found','Bundle not found.',404);
    }

    /** @return array<string,mixed>|WP_Error */
    public function transition(string $entity,int $id,string $to)
    {
        $map=['spec'=>[Tables::asset_specs(),'spec'],'plan'=>[Tables::production_plans(),'plan'],'intent'=>[Tables::production_intents(),'intent'],'revision'=>[Tables::asset_revisions(),'revision'],'bundle'=>[Tables::release_bundles(),'bundle']];
        if(!isset($map[$entity]))return $this->error('validation','Unknown production entity.');[$table,$kind]=$map[$entity];$row=$this->find($table,$id);if($row===null)return $this->error('not_found','Production record not found.',404);
        $to=strtoupper(sanitize_key($to)); if(!Lifecycle::can($kind,(string)$row['state'],$to))return $this->error('invalid_transition','Illegal production state transition.',409);
        $data=['state'=>$to,'updated_at'=>$this->now()];if(in_array($to,['APPROVED'],true)&&in_array($kind,['plan','bundle'],true)){$data['approved_by']=get_current_user_id();$data['approved_at']=$this->now();}
        global $wpdb;if($wpdb->update($table,$data,['id'=>$id])===false)return $this->error('update_failed','Unable to update production state.',500);Logger::audit('production_state_changed',['entity'=>$entity,'to'=>$to],$entity,(string)$id);return $this->find($table,$id)??$this->error('not_found','Production record not found.',404);
    }

    public function list(string $entity,int $page=1,int $perPage=20):array
    {
        $map=['specs'=>Tables::asset_specs(),'plans'=>Tables::production_plans(),'intents'=>Tables::production_intents(),'revisions'=>Tables::asset_revisions(),'qa'=>Tables::production_qa(),'bundles'=>Tables::release_bundles()];if(!isset($map[$entity]))return['items'=>[],'pagination'=>['page'=>1,'per_page'=>20,'total_items'=>0,'total_pages'=>0]];
        global $wpdb;$page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;$table=$map[$entity];$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT %d OFFSET %d',$perPage,$offset),ARRAY_A)?:[];$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$table);return['items'=>array_map([$this,'normalize'],$rows),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total_items'=>$total,'total_pages'=>(int)ceil($total/$perPage)]];
    }

    /** @return array<string,mixed>|WP_Error */
    private function insert(string $table,?string $key,array $data,string $type)
    {
        global $wpdb;$key=$key===null?null:sanitize_text_field($key);if($key==='')$key=null;if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key too long.');if($key!==null){$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE idempotency_key=%s',$key),ARRAY_A);if(is_array($existing))return $this->normalize($existing)+['idempotent_replay'=>true];$data['idempotency_key']=$key;}
        if(!$wpdb->insert($table,$data))return $this->error('create_failed','Unable to create production record.',500);Logger::audit($type.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$type,(string)$wpdb->insert_id);return $this->find($table,(int)$wpdb->insert_id)??$this->error('create_failed','Unable to read production record.',500);
    }

    private function structuredPair(array $input):array|WP_Error
    {
        $c=$this->validator->boundedStructured((array)($input['content_requirements']??[]));if(is_wp_error($c))return $c;$d=$this->validator->boundedStructured((array)($input['design_constraints']??[]));if(is_wp_error($d))return $d;return['content_requirements'=>$c,'design_constraints'=>$d];
    }
    private function exists(string $table,int $id):bool{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE id=%d',$id))>0;}
    private function find(string $table,int $id):?array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);return is_array($r)?$this->normalize($r):null;}
    public function normalize(array $row):array{unset($row['idempotency_key']);foreach(['content_requirements','design_constraints','input_payload','provenance','details','manifest','readiness'] as $k){if(isset($row[$k])&&is_string($row[$k])){$d=json_decode($row[$k],true);if(is_array($d))$row[$k]=$d;}}return $row;}
    private function now():string{return current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400):WP_Error{return new WP_Error('digiforge_'.$code,__($message,'digiforge'),['status'=>$status]);}
}
