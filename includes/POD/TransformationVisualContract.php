<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Orders\Validator;
use WP_Error;

/** Visual contract schema 1.1 under owner-approved policy 1.2; policy approval is not artwork certification. */
final class TransformationVisualContract
{
    public const VERSION='1.1';
    public const POLICY_VERSION='1.2';
    public const MODES=['controlled_painterly_illustration','stylized_poster_art'];
    public const HEADERS=['fixture_id','engine_id','mode','role_positive_negative_edge','contract_version','source_asset_sha256','reference_asset_sha256','source_mime','source_width_px','source_height_px','rights_holder','license_document_id','commercial_derivatives_permitted','ai_processing_permitted','consent_evidence_id','retention_policy_id','supplier_template_version','reviewer','review_utc','rubric_version','review_status','evidence_location','notes'];
    public static function rubric(string $engine,?string $mode=null):array
    {
        if(!in_array($engine,TransformationEngineContract::ENGINES,true)||($engine==='AI_TRANSFORM'&&!in_array($mode,self::MODES,true))||($engine!=='AI_TRANSFORM'&&$mode!==null&&$mode!==''))throw new \InvalidArgumentException('Exact approved engine/mode required.');
        $critical=match($engine){
            'PET_PORTRAIT'=>['species','coat_pattern','eye_ear_shape','unique_marks','pet_count'],
            'WATERCOLOR'=>['botanical_identity','soft_pigments','fine_detail_legibility','approved_palette','no_muddy_artifacts'],
            'LINE_ART'=>['defining_contours','recognizable_subject','contrast','no_accidental_gaps','supplier_stroke_width'],
            'VINTAGE_PORTRAIT'=>['recognizable_subject','historical_inspired_coherence','no_deceptive_provenance_or_identity'],
            'AI_TRANSFORM'=>['subject_count','identity_recognizability','approved_composition','no_unauthorized_objects_or_text']};
        return ['visual_contract_version'=>self::VERSION,'visual_policy_version'=>self::POLICY_VERSION,'engine_id'=>$engine,'mode'=>$mode,'critical_dimensions'=>$critical,'additional_dimensions'=>$engine==='PET_PORTRAIT'?['crop','background']:[],
            'hard_rejects'=>['unauthorized_subject_change','identifying_marks_or_anatomy_changed','unsafe_or_infringing_additions','unreadable_requested_text','clipped_subject','print_edge_or_bleed_failure','personalization_inconsistent','cross_shop_lineage'],
            'rating_scale'=>[0,1,2,3],'critical_dimension_minimum'=>2,'applicable_dimension_mean_minimum'=>2.5,'hard_rejects_allowed'=>0,
            'initial_samples_per_mode'=>['positive'=>5,'negative'=>3,'edge'=>2],'required_independent_reviewers'=>2,
            'thresholds_approved'=>true,'owner_designated_reviewer_verified'=>false,'commercial_quality_certified'=>false];
    }
    public static function parseCsv(string $bytes):array|WP_Error
    {
        if(strlen($bytes)>1048576||str_contains($bytes,"\0")||preg_match('//u',$bytes)!==1)return self::error('csv_bounds');
        $stream=fopen('php://temp','w+b');if($stream===false)return self::error('csv_unavailable');fwrite($stream,$bytes);rewind($stream);
        try{if(fgetcsv($stream,0,',','"','')!==self::HEADERS)return self::error('csv_header');$rows=[];$ids=[];
            while(($cells=fgetcsv($stream,0,',','"',''))!==false){if(count($rows)>=1000||count($cells)!==count(self::HEADERS))return self::error('csv_row_shape');$row=self::row(array_combine(self::HEADERS,$cells));if(isset($ids[$row['fixture_id']]))return self::error('duplicate_fixture_id');$ids[$row['fixture_id']]=true;$rows[]=$row;}
            if($rows===[])return self::error('csv_empty');return ['schema_version'=>self::VERSION,'manifest_sha256'=>hash('sha256',$bytes),'fixtures'=>$rows,'visual_acceptance_state'=>'VISUAL_REVIEW_PENDING','commercial_quality_certified'=>false];
        }catch(\InvalidArgumentException){return self::error('csv_contract');}finally{fclose($stream);}
    }
    public static function row(array $row):array
    {
        TransformationEngineContract::exact($row,self::HEADERS);if(count($row)!==count(self::HEADERS))throw new \InvalidArgumentException('Complete CSV field set required.');
        foreach($row as $value)if(!is_string($value)||strlen($value)>1000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Bounded text fields required.');
        TransformationEngineContract::token($row['fixture_id']);if($row['engine_id']==='AI_TRANSFORM'&&$row['mode']===''&&$row['review_status']==='MISSING'){self::rubric('AI_TRANSFORM',self::MODES[0]);}else{self::rubric($row['engine_id'],$row['mode']===''?null:$row['mode']);}
        // AI placeholder rows may omit mode only while explicitly MISSING.
        if($row['contract_version']!==self::VERSION||!in_array($row['role_positive_negative_edge'],['positive','negative','edge'],true)||!in_array($row['review_status'],['MISSING','VISUAL_REVIEW_PENDING','VISUAL_ACCEPTED','REJECTED'],true))throw new \InvalidArgumentException('Exact fixture role/version/state required.');
        foreach(['source_asset_sha256','reference_asset_sha256'] as $f)if($row[$f]!=='')TransformationEngineContract::hash($row[$f]);
        foreach(['source_width_px','source_height_px','supplier_template_version'] as $f)if($row[$f]!==''&&(!preg_match('/^[1-9][0-9]{0,8}$/D',$row[$f])||($f!=='supplier_template_version'&&(int)$row[$f]>10000)))throw new \InvalidArgumentException('Positive bounded dimensions/version required.');
        if($row['source_width_px']!==''&&$row['source_height_px']!==''&&(int)$row['source_width_px']*(int)$row['source_height_px']>RasterArtworkCodec::MAX_PIXELS)throw new \InvalidArgumentException('Pixel work bound exceeded.');
        foreach(['commercial_derivatives_permitted','ai_processing_permitted'] as $f)if(!in_array($row[$f],['','true','false'],true))throw new \InvalidArgumentException('Explicit true/false declarations required.');
        if($row['source_mime']!==''&&$row['source_mime']!=='image/png')throw new \InvalidArgumentException('Only currently supported fully decoded source format permitted.');
        if($row['evidence_location']!==''&&(!preg_match('~^digiforge/product-factory/[A-Za-z0-9_./-]+$~D',$row['evidence_location'])||array_intersect(explode('/',$row['evidence_location']),['.','..',''])!==[]))throw new \InvalidArgumentException('Protected local immutable evidence reference required.');
        if($row['review_utc']!==''){$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$row['review_utc'],new \DateTimeZone('UTC'));if($d===false||$d->format('Y-m-d H:i:s')!==$row['review_utc']||$row['review_utc']>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Exact nonfuture UTC review required.');}
        $required=array_diff(self::HEADERS,['mode','notes']);$complete=true;foreach($required as $f)if($row[$f]==='')$complete=false;
        $complete=$complete&&$row['review_status']!=='MISSING'&&$row['commercial_derivatives_permitted']==='true'&&$row['ai_processing_permitted']==='true';
        return $row+['evidence_complete'=>$complete,'commercial_quality_certified'=>false];
    }
    public static function bind(array $row,array $job):true|WP_Error
    {
        try{unset($row['evidence_complete'],$row['commercial_quality_certified']);$row=self::row($row);}catch(\InvalidArgumentException){return self::error('fixture_schema');}
        $rights=$job['rights'];$r=$job['request'];$source=$job['source'];
        if(!$row['evidence_complete']||$rights['evidence_kind']!=='OWNER_SUPPLIED')return self::error('licensed_fixture_required');
        foreach(['fixture_id'=>'fixture_id','rights_holder'=>'license_holder','license_document_id'=>'license_document_id','consent_evidence_id'=>'consent_evidence_id','retention_policy_id'=>'retention_policy_id','reviewer'=>'review_owner','rubric_version'=>'qa_rubric_revision','evidence_location'=>'storage_location'] as $csv=>$field)if($row[$csv]!==($rights[$field]??null))return self::error('fixture_provenance_conflict');
        if($row['engine_id']!==$r['engine_id']||$row['source_asset_sha256']!==$source['sha256']||$row['reference_asset_sha256']!==($rights['reference_asset_sha256']??$rights['result_asset_sha256'])||$row['source_mime']!==$source['mime']||(int)$row['source_width_px']!==$source['width_px']||(int)$row['source_height_px']!==$source['height_px']||(int)$row['supplier_template_version']!==$r['template_version']||($row['engine_id']==='AI_TRANSFORM'&&$row['mode']!==$r['options']['mode']))return self::error('fixture_artifact_context_conflict');
        return true;
    }
    public static function review(array $job,array $review):array|WP_Error
    {
        try{TransformationEngineContract::exact($review,['visual_contract_version','scores','hard_rejects','source_sha256','reference_sha256','output_sha256','template_sha256','print_mockup','decision','reason']);$rubric=self::rubric($job['request']['engine_id'],$job['request']['options']['mode']??null);
            if(($review['visual_contract_version']??null)!==self::VERSION||!in_array($review['decision']??null,['RECORD_REVIEW','REJECT'],true)||!is_string($review['reason']??null)||$review['reason']===''||strlen($review['reason'])>1000)throw new \InvalidArgumentException();
            foreach(['scores','hard_rejects'] as $f){$keys=$f==='scores'?array_merge($rubric['critical_dimensions'],$rubric['additional_dimensions']):$rubric['hard_rejects'];$actual=array_keys($review[$f]??[]);sort($actual);sort($keys);if($actual!==$keys)throw new \InvalidArgumentException();foreach($review[$f] as $value)if($f==='scores'?(!is_int($value)||$value<0||$value>3):!is_bool($value))throw new \InvalidArgumentException();}
            foreach(['source_sha256'=>$job['source']['sha256'],'reference_sha256'=>$job['rights']['reference_asset_sha256']??$job['rights']['result_asset_sha256'],'output_sha256'=>$job['result']['sha256'],'template_sha256'=>$job['request']['template_sha256']] as $f=>$expected)if(($review[$f]??null)!==$expected)throw new \InvalidArgumentException();
            $mockup=$review['print_mockup']??null;if(!is_array($mockup)||!str_starts_with($mockup['storage_reference']??'',\DigiForge\ProductFactory\AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/shop-'.$job['shop_key'].'/'))throw new \InvalidArgumentException();$verified=(new RenderArtifactStore())->verify($mockup);if($verified instanceof WP_Error)return $verified;if($verified['width_px']!==$job['result']['width_px']||$verified['height_px']!==$job['result']['height_px'])throw new \InvalidArgumentException();
            $rejected=$review['decision']==='REJECT'||in_array(true,$review['hard_rejects'],true);
            return $review+['review_sha256'=>hash('sha256',Validator::canonicalJson($review)),'visual_policy_version'=>self::POLICY_VERSION,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('Y-m-d H:i:s'),'visual_acceptance_state'=>$rejected?'REJECTED':'VISUAL_REVIEW_PENDING','thresholds_approved'=>true,'owner_designated_reviewer_verified'=>false,'commercial_quality_certified'=>false,'supplier_certified'=>false];
        }catch(\InvalidArgumentException|\TypeError){return self::error('review_contract');}
    }
    private static function error(string $code):WP_Error{return new WP_Error('transformation_visual_'.$code,'Visual evidence is missing, inconsistent or outside the approved contract.',['status'=>409,'commercial_quality_certified'=>false,'production_ready'=>false]);}
}
