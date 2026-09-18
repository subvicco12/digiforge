<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/** Persistence boundary for immutable, versioned production-template candidates. */
final class ProductionTemplateRepository
{
    public function save(array $input): array|WP_Error
    {
        try{$row=ProductionTemplateContract::normalize($input);}catch(\Throwable $e){return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);}
        global $wpdb;
        $table=\DigiForge\Database\Tables::pod_production_templates();
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE template_id=%s AND template_version=%d LIMIT 1',$row['template_id'],$row['template_version']),ARRAY_A);
        if(is_array($existing)){
            if(hash_equals((string)($existing['fingerprint']??''),(string)$row['fingerprint'])) return $existing+['idempotent'=>true];
            try{ProductionTemplateContract::assertMutable($existing);}catch(\LogicException $e){return new WP_Error('digiforge_template_immutable',$e->getMessage(),['status'=>409]);}
            return new WP_Error('digiforge_template_version_conflict','A changed production template requires a new template_version.',['status'=>409]);
        }
        $data=[
            'template_id'=>$row['template_id'],'template_version'=>$row['template_version'],'supplier'=>$row['supplier'],
            'provider_blueprint_id'=>$row['provider_blueprint_id'],'provider_id'=>$row['provider_id'],
            'variant_ids'=>wp_json_encode($row['variant_ids']),'print_areas'=>wp_json_encode($row['print_areas']),
            'personalization_pipeline'=>$row['personalization_pipeline'],'personalization_engine'=>$row['personalization_engine'],
            'template_status'=>$row['template_status'],'fingerprint'=>$row['fingerprint'],
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),
        ];
        if(!is_string($data['variant_ids'])||!is_string($data['print_areas'])) return new WP_Error('digiforge_template_encode','Production template metadata could not be encoded.',['status'=>500]);
        if($wpdb->insert($table,$data)===false) return new WP_Error('digiforge_template_insert','Production template could not be stored.',['status'=>500]);
        return $data+['id'=>(int)$wpdb->insert_id,'idempotent'=>false];
    }

    /** Catalog/geometry drift never mutates an existing version; it creates the next DRAFT candidate. */
    public function createDriftCandidate(array $input): array|WP_Error
    {
        $templateId=trim((string)($input['template_id']??''));
        if($templateId==='') return new WP_Error('digiforge_template_id_required','template_id is required for catalog drift.',['status'=>400]);
        global $wpdb;
        $table=\DigiForge\Database\Tables::pod_production_templates();
        $latest=$wpdb->get_row($wpdb->prepare('SELECT template_version,fingerprint FROM '.$table.' WHERE template_id=%s ORDER BY template_version DESC LIMIT 1',$templateId),ARRAY_A);
        $input['template_version']=is_array($latest)?((int)$latest['template_version']+1):1;
        $input['template_status']='DRAFT';
        try{$candidate=ProductionTemplateContract::normalize($input);}catch(\Throwable $e){return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);}
        if(is_array($latest)&&hash_equals((string)($latest['fingerprint']??''),(string)$candidate['fingerprint'])) return new WP_Error('digiforge_template_no_drift','No production-template drift was detected.',['status'=>409]);
        return $this->save($candidate);
    }
}
