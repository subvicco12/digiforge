<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/** Persistence boundary for immutable, versioned production-template candidates. */
final class ProductionTemplateRepository
{
    public function save(array $input): array|WP_Error
    {
        try{$normalized=ProductionTemplateContract::normalize($input);}catch(\Throwable $e){return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);}
        if(sanitize_key((string)($input['shop_key']??''))==='')return new WP_Error('template_cycle_scope_required','A shop is required for production-template access.');
        global $wpdb;$lock='digiforge-template-'.substr(hash('sha256',$normalized['template_id']),0,45);
        $wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if(!empty($wpdb->last_error)||(string)$acquired!=='1')return new WP_Error('template_identity_busy','Template identity is unavailable or busy.');
        try{$result=$this->saveLocked($input);}catch(\Throwable $e){$result=new WP_Error('template_persistence_uncertain','Template persistence is uncertain; reconciliation is required.');}
        finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$uncertain=!empty($wpdb->last_error)||(string)$released!=='1';}
        if($uncertain)return new WP_Error('template_identity_release_uncertain','Template identity lock release is uncertain; reconciliation is required.');
        return $result;
    }

    private function saveLocked(array $input): array|WP_Error
    {
        try{$row=ProductionTemplateContract::normalize($input);}catch(\Throwable $e){return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);}
        $shop=sanitize_key((string)($input['shop_key']??''));
        if($shop==='')return new WP_Error('template_cycle_scope_required','A shop is required for production-template access.');
        global $wpdb;
        $table=\DigiForge\Database\Tables::pod_production_templates();
        $wpdb->last_error='';
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE template_id=%s AND template_version=%d LIMIT 1',$row['template_id'],$row['template_version']),ARRAY_A);
        if(!empty($wpdb->last_error)) return new WP_Error('digiforge_template_evidence_unavailable','Production template evidence could not be read.',['status'=>503,'retry_permitted'=>false]);
        if(is_array($existing)){
            if(hash_equals((string)($existing['fingerprint']??''),(string)$row['fingerprint'])){$owner=(new TemplateCycleRepository())->assertOwner($shop,$row['fingerprint']);if($owner instanceof WP_Error)return $owner;return $existing+['idempotent'=>true];}
            try{ProductionTemplateContract::assertMutable($existing);}catch(\LogicException $e){return new WP_Error('digiforge_template_immutable',$e->getMessage(),['status'=>409]);}
            return new WP_Error('digiforge_template_version_conflict','A changed production template requires a new template_version.',['status'=>409]);
        }
        $wpdb->last_error='';$latest=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE template_id=%s ORDER BY template_version DESC LIMIT 1',$row['template_id']),ARRAY_A);
        if(!empty($wpdb->last_error))return new WP_Error('digiforge_template_evidence_unavailable','Template lineage ownership could not be read.');
        if(is_array($latest)){$owner=(new TemplateCycleRepository())->assertOwner($shop,(string)$latest['fingerprint']);if($owner instanceof WP_Error)return $owner;}
        $reservation=(new TemplateCycleRepository())->reserve((string)($input['shop_key']??''),$row);
        if($reservation instanceof WP_Error)return $reservation;
        $data=[
            'template_id'=>$row['template_id'],'template_version'=>$row['template_version'],'supplier'=>$row['supplier'],
            'provider_blueprint_id'=>$row['provider_blueprint_id'],'provider_id'=>$row['provider_id'],
            'variant_ids'=>wp_json_encode($row['variant_ids']),'print_areas'=>wp_json_encode($row['print_areas']),
            'personalization_pipeline'=>$row['personalization_pipeline'],'personalization_engine'=>$row['personalization_engine'],
            'template_status'=>$row['template_status'],'fingerprint'=>$row['fingerprint'],
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),
        ];
        if(!is_string($data['variant_ids'])||!is_string($data['print_areas'])) return new WP_Error('digiforge_template_encode','Production template metadata could not be encoded.',['status'=>500]);
        if($wpdb->insert($table,$data)===false){$wpdb->last_error='';$winner=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE template_id=%s AND template_version=%d LIMIT 1',$row['template_id'],$row['template_version']),ARRAY_A);if(!empty($wpdb->last_error))return new WP_Error('digiforge_template_confirmation_unavailable','Template persistence outcome is uncertain; do not retry automatically.',['status'=>503,'retry_permitted'=>false]);if(is_array($winner)&&hash_equals((string)($winner['fingerprint']??''),(string)$row['fingerprint']))return $winner+['idempotent'=>true];return new WP_Error('digiforge_template_insert','Production template could not be stored.',['status'=>500]);}
        return $data+['id'=>(int)$wpdb->insert_id,'idempotent'=>false];
    }

    /** Catalog/geometry drift never mutates an existing version; it creates the next DRAFT candidate. */
    public function createDriftCandidate(array $input): array|WP_Error
    {
        $templateId=trim((string)($input['template_id']??''));
        if($templateId==='') return new WP_Error('digiforge_template_id_required','template_id is required for catalog drift.',['status'=>400]);
        global $wpdb;
        $table=\DigiForge\Database\Tables::pod_production_templates();
        $wpdb->last_error='';
        $latest=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE template_id=%s ORDER BY template_version DESC LIMIT 1',$templateId),ARRAY_A);
        if(!empty($wpdb->last_error)) return new WP_Error('digiforge_template_evidence_unavailable','Latest production template evidence could not be read.',['status'=>503,'retry_permitted'=>false]);
        $input['template_version']=is_array($latest)?((int)$latest['template_version']+1):1;
        $input['template_status']='DRAFT';
        try{$candidate=ProductionTemplateContract::normalize($input);}catch(\Throwable $e){return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);}
        if(is_array($latest)){
            $owner=(new TemplateCycleRepository())->assertOwner((string)($input['shop_key']??''),(string)$latest['fingerprint']);if($owner instanceof WP_Error)return $owner;
            $prior=$latest;$prior['variant_ids']=json_decode((string)($prior['variant_ids']??'[]'),true);$prior['print_areas']=json_decode((string)($prior['print_areas']??'[]'),true);
            try{$priorNormalized=ProductionTemplateContract::normalize($prior);$priorMaterial=ProductionTemplateContract::materialFingerprint($priorNormalized);$candidateMaterial=ProductionTemplateContract::materialFingerprint($candidate);}catch(\Throwable $e){return new WP_Error('digiforge_template_drift_evidence',$e->getMessage(),['status'=>500]);}
            if(hash_equals($priorMaterial,$candidateMaterial)) return new WP_Error('digiforge_template_no_drift','No production-template drift was detected.',['status'=>409]);
        }
        $candidate['shop_key']=(string)($input['shop_key']??'');
        return $this->save($candidate);
    }
}
