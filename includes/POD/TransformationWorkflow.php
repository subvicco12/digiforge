<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use DigiForge\Portal\ProductEvidenceScope;
use DigiForge\ProductFactory\AssetStorage;
use DigiForge\Security\Logger;
use WP_Error;

/** Shop-scoped immutable journal for local transformation review. Never calls a provider. */
final class TransformationWorkflow
{
    public function registerMode(string $shop,array $mode):array|WP_Error
    {
        return $this->run($shop,'mode-'.hash('sha256',Validator::canonicalJson($mode)),function()use($shop,$mode){
            TransformationEngineContract::exact($mode,['mode','version','prompt_revision','prompt_sha256','fixture_manifest_sha256','input_fields','evidence_kind']);
            foreach(['mode','version','prompt_revision'] as $field)TransformationEngineContract::token($mode[$field]??null);
            if($mode['version']!==TransformationEngineContract::VERSION||!in_array($mode['evidence_kind']??null,['SYNTHETIC_TEST_ONLY','OWNER_SUPPLIED'],true))throw new \InvalidArgumentException('Exact mode revision/evidence required.');
            if($mode['evidence_kind']==='OWNER_SUPPLIED'&&!in_array($mode['mode'],TransformationVisualContract::MODES,true))throw new \InvalidArgumentException('Only named owner-approved artistic modes permitted.');
            foreach(['prompt_sha256','fixture_manifest_sha256'] as $field)TransformationEngineContract::hash($mode[$field]??null);
            if(!is_array($mode['input_fields']??null)||count($mode['input_fields'])>20)throw new \InvalidArgumentException('Bounded typed mode schema required.');
            // Validate schema independently of required variable values.
            $fields=$mode['input_fields'];foreach($fields as &$field){if(!is_array($field))throw new \InvalidArgumentException('Typed mode fields required.');$field['required']=false;}unset($field);PersonalizationPayloadContract::validate($fields,[]);
            $name=$this->modeKey($shop,$mode['mode'],$mode['version']);$record=$mode+['shop_key'=>$shop,'reviewed_by'=>get_current_user_id()];$old=$this->option($name);if($old instanceof WP_Error)return $old;
            if($old!==null)return Validator::canonicalJson($old)===Validator::canonicalJson($record)?$old:self::error('immutable_mode_conflict');
            return $this->insert($name,$record);
        });
    }
    public function create(string $shop,string $key,array $request,string $sourcePath,array $rights):array|WP_Error
    {
        return $this->run($shop,$key,function()use($shop,$key,$request,$sourcePath,$rights){
            TransformationEngineContract::exact($request,['engine_id','contract_version','product_version_id','template_id','template_version','template_sha256','personalization_sha256','personalization_submission_id','options','limits']);
            $engine=TransformationEngineContract::token($request['engine_id']??null);$version=TransformationEngineContract::token($request['contract_version']??null);
            foreach(['product_version_id','template_version'] as $field)if(!is_int($request[$field]??null)||$request[$field]<1)throw new \InvalidArgumentException('Positive typed context/version required.');
            TransformationEngineContract::token($request['template_id']??null);foreach(['template_sha256','personalization_sha256'] as $field)TransformationEngineContract::hash($request[$field]??null);
            if(!is_array($request['options']??null)||!is_array($request['limits']??null))throw new \InvalidArgumentException('Typed options and explicit host bounds required.');
            $mode=null;if($engine==='AI_TRANSFORM'){$mode=$this->option($this->modeKey($shop,TransformationEngineContract::token($request['options']['mode']??null),TransformationEngineContract::token($request['options']['mode_version']??null)));if($mode instanceof WP_Error)return $mode;}
            $request['options']=TransformationEngineContract::options($engine,$version,$request['options'],$mode);$request['limits']=TransformationEngineContract::limits($request['limits']);
            $manifest=TransformationFixtureManifest::normalize($rights);if($manifest['engine_id']!==$engine||$manifest['template_id']!==$request['template_id'])throw new \InvalidArgumentException('Fixture, engine and frozen template must agree.');
            if($engine==='AI_TRANSFORM'&&(($manifest['mode']??null)!==$mode['mode']||$manifest['evidence_kind']!==$mode['evidence_kind']||!hash_equals($mode['fixture_manifest_sha256'],hash('sha256',Validator::canonicalJson($manifest)))))return self::error('mode_fixture_conflict');
            $personalization=$this->personalization($shop,$request,$manifest);if($personalization instanceof WP_Error)return $personalization;
            $context=$this->context($shop,$request);if($context instanceof WP_Error)return $context;
            $source=$this->inspect($sourcePath,$request['limits'],'input');if($source instanceof WP_Error)return $source;if($manifest['source_asset_sha256']!==$source['sha256'])return self::error('source_fixture_conflict');
            $rightsCheck=$this->rights($manifest);if($rightsCheck instanceof WP_Error)return $rightsCheck;if(isset($manifest['visual_fixture'])){$bound=TransformationVisualContract::bind($manifest['visual_fixture'],['rights'=>$manifest,'request'=>$request,'source'=>$source]);if($bound instanceof WP_Error)return $bound;}
            $fingerprint=hash('sha256',Validator::canonicalJson(['shop_key'=>$shop,'job_key_sha256'=>hash('sha256',$key),'request'=>$request,'source'=>$source,'rights'=>$manifest]));$current=$this->load($shop,$key);if($current instanceof WP_Error)return $current;if($current!==null)return $current['job_hash']===$fingerprint?$current:self::error('idempotency_conflict');
            $stored=(new RenderArtifactStore())->retain($sourcePath,$source,$shop);if($stored instanceof WP_Error)return $stored;
            $record=['job_hash'=>$fingerprint,'shop_key'=>$shop,'request'=>$request,'rights'=>$manifest,'source'=>$stored,'state'=>'DRAFT','certification'=>$manifest['evidence_kind']==='SYNTHETIC_TEST_ONLY'?'SYNTHETIC_TEST_ONLY':'NOT_CERTIFIED','production_ready'=>false,'color_profile_certification'=>'UNKNOWN_NOT_CERTIFIED','external_execution_authorized'=>false,'external_execution_performed'=>false,'charge_provenance_state'=>'UNATTRIBUTED','created_by'=>get_current_user_id(),'created_at'=>gmdate('Y-m-d H:i:s')];
            return $this->append($shop,$key,null,$record);
        });
    }
    public function verifyInput(string $shop,string $key):array|WP_Error{return $this->transition($shop,$key,'DRAFT','INPUT_VERIFIED',static fn(array $job)=>[]);}
    public function importResult(string $shop,string $key,string $path,array $attribution):array|WP_Error
    {
        return $this->run($shop,$key,function()use($shop,$key,$path,$attribution){
            $job=$this->read($shop,$key);if($job instanceof WP_Error)return $job;
            if(!in_array($job['state'],['INPUT_VERIFIED','GENERATED'],true))return self::error('invalid_transition');
            TransformationEngineContract::exact($attribution,['evidence_kind','provider','model','prompt_version','config_sha256']);
            if(($attribution['evidence_kind']??null)!==$job['rights']['evidence_kind'])return self::error('attribution_evidence_required');
            foreach(['provider','model','prompt_version'] as $field)TransformationEngineContract::token($attribution[$field]??null);TransformationEngineContract::hash($attribution['config_sha256']??null);
            // An owner-imported result is neither an executed provider request nor proof of a monetary charge.
            $result=$this->inspect($path,$job['request']['limits'],'output');if($result instanceof WP_Error)return $result;
            if($result['sha256']!==$job['rights']['result_asset_sha256'])return self::error('result_fixture_conflict');if(isset($job['rights']['pixel_size'])&&($job['rights']['MIME']!==$result['mime']||$job['rights']['pixel_size']['width_px']!==$result['width_px']||$job['rights']['pixel_size']['height_px']!==$result['height_px']))return self::error('result_fixture_geometry_conflict');
            if($job['state']==='GENERATED')return $job['result']['sha256']===$result['sha256']&&Validator::canonicalJson($job['attribution'])===Validator::canonicalJson($attribution)?$job:self::error('immutable_result_conflict');
            $stored=(new RenderArtifactStore())->retain($path,$result,$shop);if($stored instanceof WP_Error)return $stored;
            return $this->append($shop,$key,$job,array_replace($job,['state'=>'GENERATED','result'=>$stored,'attribution'=>$attribution]));
        });
    }
    public function technicalQa(string $shop,string $key):array|WP_Error
    {
        return $this->transition($shop,$key,'GENERATED','TECHNICAL_QA',function(array $job)use($shop){
            $template=$this->context($shop,$job['request']);if($template instanceof WP_Error)return $template;
            if(count($template['print_areas'])!==1)return self::error('complete_panel_adapter_required');$area=$template['print_areas'][0];$result=$job['result'];
            if($area['width_px']!==$result['width_px']||$area['height_px']!==$result['height_px'])return self::error('print_geometry_conflict');
            $policy=ArtworkGeometryQa::policy($area);if($policy['state']!=='VERIFIED_LOCAL_GEOMETRY'||$policy['bleed_state']!=='VERIFIED_LOCAL_BLEED_GEOMETRY')return self::error('supplier_geometry_evidence_required');
            $qa=(new StoredArtworkVisualQa())->verify([$area],[$area['position']=>$result]);return $qa instanceof WP_Error?$qa:['technical_qa'=>$qa,'font_and_stroke_certification'=>'REQUIRES_APPROVED_STYLE_AND_SUPPLIER_EVIDENCE'];
        });
    }
    public function requestReview(string $shop,string $key):array|WP_Error{return $this->transition($shop,$key,'TECHNICAL_QA','HUMAN_REVIEW',static fn(array $job)=>[]);}
    public function review(string $shop,string $key,string $decision,array $review):array|WP_Error
    {
        if(!in_array($decision,['APPROVE_LOCAL_REVIEW','REJECT'],true))return self::error('explicit_local_review_required');
        return $this->transition($shop,$key,'HUMAN_REVIEW',$decision==='REJECT'?'REJECTED':'APPROVED',function(array $job)use($review,$decision){
            TransformationEngineContract::exact($review,['rubric_revision','checks','reference_sha256','reason']);if(($review['rubric_revision']??null)!==$job['rights']['qa_rubric_revision']||($review['reference_sha256']??null)!==$job['rights']['result_asset_sha256'])return self::error('visual_reference_conflict');
            if(!is_string($review['reason']??null)||$review['reason']===''||strlen($review['reason'])>1000||!is_array($review['checks']??null)||array_keys($review['checks'])!==['identity','style','print_legibility'])return self::error('visual_review_evidence_required');foreach($review['checks'] as $value)if(!is_bool($value)||($decision==='APPROVE_LOCAL_REVIEW'&&!$value))return self::error('visual_review_failed');
            return ['review'=>$review+['reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('Y-m-d H:i:s')],'production_ready'=>false];
        });
    }
    public function recordVisualReview(string $shop,string $key,array $review):array|WP_Error
    {
        return $this->run($shop,$key,function()use($shop,$key,$review){$job=$this->read($shop,$key);if($job instanceof WP_Error)return $job;
            if($job['state']!=='HUMAN_REVIEW'&&!isset($job['visual_review']))return self::error('invalid_transition');$evidence=TransformationVisualContract::review($job,$review);if($evidence instanceof WP_Error)return $evidence;
            if(isset($job['visual_review']))return $job['visual_review']['review_sha256']===$evidence['review_sha256']?$job:self::error('immutable_visual_review_conflict');
            if($job['state']!=='HUMAN_REVIEW')return self::error('invalid_transition');
            return $this->append($shop,$key,$job,array_replace($job,['visual_review'=>$evidence,'state'=>$evidence['visual_acceptance_state']==='REJECTED'?'REJECTED':'HUMAN_REVIEW','production_ready'=>false]));});
    }
    public function visualCertification(string $shop,string $key):array|WP_Error
    {
        $job=$this->read($shop,$key);if($job instanceof WP_Error)return $job;$blockers=['OWNER_DESIGNATED_REVIEWERS_AND_TWO_SIGNOFFS_REQUIRED','APPROVED_INITIAL_PER_MODE_FIXTURE_SET_REQUIRED','SUPPLIER_PRINT_CERTIFICATION_REQUIRED','PROVIDER_PERMISSIONS_AND_SPENDING_CEILINGS_REQUIRED','RELEASE_AUTHORIZATION_REQUIRED'];
        if($job['rights']['evidence_kind']!=='OWNER_SUPPLIED'||!isset($job['rights']['visual_fixture']))$blockers[]='LICENSED_FIXTURES_REQUIRED';
        if(!isset($job['visual_review']))$blockers[]='ENGINE_SPECIFIC_VISUAL_REVIEW_REQUIRED';
        if($job['request']['engine_id']==='VINTAGE_PORTRAIT')$blockers[]='FIRST_APPROVED_PRODUCT_AND_ERA_REQUIRED';
        return ['visual_contract_state'=>'CONTRACT_APPROVED','visual_acceptance_state'=>$job['state']==='REJECTED'?'REJECTED':($job['visual_review']['visual_acceptance_state']??'VISUAL_REVIEW_PENDING'),'print_state'=>'PRINT_CERTIFICATION_PENDING','release_state'=>'RELEASE_BLOCKED','blockers'=>$blockers,'commercial_quality_certified'=>false,'supplier_certified'=>false,'catalog_activation_allowed'=>false,'production_ready'=>false,'external_execution_authorized'=>false];
    }
    public function cancel(string $shop,string $key):array|WP_Error
    {
        return $this->run($shop,$key,function()use($shop,$key){$job=$this->load($shop,$key);if($job instanceof WP_Error)return $job;if($job===null)return self::error('not_found');if($job['state']==='CANCELLED')return $job;if(in_array($job['state'],['APPROVED','REJECTED'],true))return self::error('terminal_review_immutable');return $this->append($shop,$key,$job,array_replace($job,['state'=>'CANCELLED']));});
    }
    public function read(string $shop,string $key):array|WP_Error
    {
        $gate=$this->gate($shop,$key);if($gate instanceof WP_Error)return $gate;$job=$this->load($shop,$key);if($job instanceof WP_Error)return $job;if($job===null)return self::error('not_found');
        $personalization=$this->personalization($shop,$job['request'],$job['rights']);if($personalization instanceof WP_Error)return $personalization;
        $context=$this->context($shop,$job['request']);if($context instanceof WP_Error)return $context;$rights=$this->rights($job['rights']);if($rights instanceof WP_Error)return $rights;if(isset($job['rights']['visual_fixture'])){$bound=TransformationVisualContract::bind($job['rights']['visual_fixture'],$job);if($bound instanceof WP_Error)return $bound;}
        if(isset($job['visual_review']['print_mockup'])){$mockup=$job['visual_review']['print_mockup'];$prefix=AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/shop-'.$shop.'/';if(!str_starts_with((string)($mockup['storage_reference']??''),$prefix))return self::error('artifact_scope_conflict');$verified=(new RenderArtifactStore())->verify($mockup);if($verified instanceof WP_Error)return $verified;}
        foreach(['source','result'] as $field)if(isset($job[$field])){$prefix=AssetStorage::RELATIVE_ROOT.'/pod-render-artifacts/shop-'.$shop.'/';if(!str_starts_with((string)($job[$field]['storage_reference']??''),$prefix))return self::error('artifact_scope_conflict');$verified=(new RenderArtifactStore())->verify($job[$field]);if($verified instanceof WP_Error)return $verified;}
        return $job;
    }
    public function artifact(string $shop,string $key,string $which):array|WP_Error
    {
        if(!in_array($which,['source','result'],true))return self::error('artifact_kind_invalid');$job=$this->read($shop,$key);if($job instanceof WP_Error)return $job;if(!isset($job[$which]))return self::error('artifact_unavailable');$path=AssetStorage::absolutePath($job[$which]['storage_reference']);if($path===null)return self::error('artifact_unavailable');$bytes=@file_get_contents($path,false,null,0,16777217);if(!is_string($bytes)||!hash_equals($job[$which]['sha256'],hash('sha256',$bytes)))return self::error('artifact_changed');return ['bytes'=>$bytes,'mime'=>$job[$which]['mime'],'sha256'=>$job[$which]['sha256'],'production_ready'=>false];
    }
    private function transition(string $shop,string $key,string $from,string $to,callable $evidence):array|WP_Error
    {
        return $this->run($shop,$key,function()use($shop,$key,$from,$to,$evidence){$job=$this->read($shop,$key);if($job instanceof WP_Error)return $job;if($job['state']!==$from)return self::error('invalid_transition');$extra=$evidence($job);return $extra instanceof WP_Error?$extra:$this->append($shop,$key,$job,array_replace($job,$extra,['state'=>$to]));});
    }
    private function gate(string $shop,string $key):true|WP_Error
    {
        if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required');if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$key))return self::error('scope_invalid');return true;
    }
    private function run(string $shop,string $key,callable $work):array|WP_Error
    {
        $gate=$this->gate($shop,$key);if($gate instanceof WP_Error)return $gate;global $wpdb;$lock='df-transform-'.substr(hash('sha256',$shop.'|'.$key),0,48);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if($wpdb->last_error!==''||(string)$acquired!=='1')return self::error('busy');
        try{$result=$work();}catch(\InvalidArgumentException){$result=self::error('contract_evidence_invalid');}catch(\Throwable){$result=self::error('persistence_uncertain');}finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$uncertain=$wpdb->last_error!==''||(string)$released!=='1';}
        if($uncertain)$result=self::error('lock_release_uncertain');if($result instanceof WP_Error)Logger::write('transformation_blocked',['code'=>$result->get_error_code()],'transformation_attempt',hash('sha256',$shop.'|'.$key));return $result;
    }
    private function context(string $shop,array $request):array|WP_Error
    {
        global $wpdb;$table=Tables::product_versions();$scope=ProductEvidenceScope::forTable($table,$shop);$wpdb->last_error='';$owned=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$table.' WHERE id=%d AND ',$request['product_version_id']).$scope);if($wpdb->last_error!==''||(string)$owned!=='1')return self::error('product_scope_conflict');
        $wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d',$request['template_id'],$request['template_version']),ARRAY_A);if($wpdb->last_error!==''||!is_array($row)||$row['template_status']!=='VALIDATED')return self::error('template_evidence_required');$row['variant_ids']=json_decode($row['variant_ids'],true);$row['print_areas']=json_decode($row['print_areas'],true);
        try{$template=ProductionTemplateContract::normalize($row);}catch(\InvalidArgumentException){return self::error('template_evidence_invalid');}if($template['fingerprint']!==$row['fingerprint']||$template['fingerprint']!==$request['template_sha256']||$template['personalization_engine']!==$request['engine_id'])return self::error('template_drift');$owner=(new TemplateCycleRepository())->assertOwner($shop,$template['fingerprint']);return $owner instanceof WP_Error?$owner:$template;
    }
    private function rights(array $manifest):true|WP_Error
    {
        if($manifest['evidence_kind']==='SYNTHETIC_TEST_ONLY')return true;
        if(($manifest['expiry']??'')<=gmdate('Y-m-d H:i:s'))return self::error('rights_expired');
        foreach(['license_uri_or_document'=>'rights_document_sha256','consent_document_reference'=>'consent_document_sha256','retention_policy_reference'=>'retention_policy_sha256'] as $referenceField=>$hashField){
            $path=AssetStorage::absolutePath($manifest[$referenceField]);if($path===null||is_link($path))return self::error('rights_document_required');$bytes=@file_get_contents($path,false,null,0,65537);if(!is_string($bytes)||strlen($bytes)>65536||!hash_equals($manifest[$hashField],hash('sha256',$bytes)))return self::error('rights_document_conflict');
        }
        $reference=AssetStorage::absolutePath($manifest['storage_location']);if($reference===null)return self::error('approved_reference_required');$artifact=(new RenderArtifactVerifier())->readArtifact($reference);return $artifact instanceof WP_Error||$artifact['sha256']!==($manifest['reference_asset_sha256']??$manifest['result_asset_sha256'])?self::error('approved_reference_conflict'):true;
    }
    private function inspect(string $path,array $limits,string $kind):array|WP_Error
    {
        $started=hrtime(true);
        if($path===''||str_contains($path,'://')||str_contains($path,"\0")||is_link($path)||!is_file($path))return self::error('local_artifact_capability_or_limit');
        $handle=@fopen($path,'rb');if($handle===false)return self::error('artifact_unavailable');
        try{$stat=fstat($handle);$header=fread($handle,24);}finally{fclose($handle);}
        if(!is_array($stat)||($stat['mode']&0170000)!==0100000||$stat['size']<24||$stat['size']>$limits['max_'.$kind.'_bytes']||!is_string($header)||strlen($header)!==24||substr($header,0,8)!=="\x89PNG\r\n\x1a\n"||substr($header,12,4)!=='IHDR')return self::error('local_artifact_capability_or_limit');
        $dimensions=unpack('Nwidth/Nheight',substr($header,16,8));if($dimensions['width']<1||$dimensions['height']<1||$dimensions['width']*$dimensions['height']>$limits['max_pixels'])return self::error('local_artifact_capability_or_limit');
        // Headers only reject early; acceptance always requires complete bounded pixel decoding.
        $artifact=(new RenderArtifactVerifier())->readArtifact($path);if($artifact instanceof WP_Error)return $artifact;
        if($artifact['mime']!=='image/png'||$artifact['bytes']>$limits['max_'.$kind.'_bytes']||$artifact['width_px']*$artifact['height_px']>$limits['max_pixels'])return self::error('local_artifact_capability_or_limit');
        $bytes=@file_get_contents($path,false,null,0,$limits['max_'.$kind.'_bytes']+1);if(!is_string($bytes)||strlen($bytes)>$limits['max_'.$kind.'_bytes']||hash('sha256',$bytes)!==$artifact['sha256'])return self::error('artifact_changed');
        for($offset=8;$offset+12<=strlen($bytes);){$length=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);if(in_array($type,['eXIf','iCCP'],true))return self::error('orientation_or_profile_adapter_required');$offset+=12+$length;}
        return (hrtime(true)-$started)/1e9>$limits['max_execution_seconds']?self::error('local_artifact_capability_or_limit'):$artifact;
    }
    private function personalization(string $shop,array $request,array $manifest):true|WP_Error
    {
        if($manifest['evidence_kind']==='SYNTHETIC_TEST_ONLY')return true;
        $id=$request['personalization_submission_id']??null;if(!is_int($id)||$id<1)return self::error('stored_personalization_required');
        global $wpdb;$scope=\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o');$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare("SELECT ps.*,s.field_definitions,s.state schema_state,s.approved_by schema_reviewer,s.approved_at schema_reviewed_at FROM ".Tables::personalization_submissions()." ps INNER JOIN ".Tables::order_line_items()." li ON li.id=ps.order_line_item_id AND li.product_version_id=%d AND li.validation_status='VALIDATED' INNER JOIN ".Tables::orders()." o ON o.id=li.order_id AND ".$scope." AND BINARY o.environment=BINARY ps.environment AND BINARY li.environment=BINARY ps.environment INNER JOIN ".Tables::personalization_schemas()." s ON s.id=ps.personalization_schema_id AND s.product_version_id=li.product_version_id WHERE ps.id=%d AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL",$request['product_version_id'],$id),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($row)||$row['schema_state']!=='APPROVED'||(int)$row['schema_reviewer']<1||$row['schema_reviewed_at']===null||strlen($row['canonical_payload'])>65536||strlen($row['field_definitions'])>65536||!hash_equals($request['personalization_sha256'],$row['payload_hash'])||!hash_equals($row['payload_hash'],hash('sha256',$row['canonical_payload'])))return self::error('stored_personalization_conflict');
        try{$payload=json_decode($row['canonical_payload'],true,16,JSON_THROW_ON_ERROR);$fields=json_decode($row['field_definitions'],true,16,JSON_THROW_ON_ERROR);if(!is_array($payload)||!is_array($fields)||Validator::canonicalJson($payload)!==$row['canonical_payload'])throw new \InvalidArgumentException();PersonalizationPayloadContract::validate($fields,$payload);}catch(\Throwable){return self::error('stored_personalization_conflict');}return true;
    }
    private function modeKey(string $shop,string $mode,string $version):string{return 'df_transform_mode_'.hash('sha256',$shop.'|'.$mode.'|'.$version);}
    private function prefix(string $shop,string $key):string{return 'df_transform_job_'.hash('sha256',$shop.'|'.$key).'_';}
    private function load(string $shop,string $key):array|WP_Error|null
    {
        global $wpdb;$prefix=$this->prefix($shop,$key);$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_name LIMIT 11',$wpdb->esc_like($prefix).'%'),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)>10)return self::error('journal_unavailable');$previous=null;
        foreach($rows as $i=>$row){$record=json_decode($row['option_value'],true);if(!is_array($record)||$row['option_name']!==$prefix.sprintf('%02d',$i)||($record['shop_key']??null)!==$shop||($record['sequence']??null)!==$i||($record['previous_event_sha256']??null)!==($previous['event_sha256']??'ROOT'))return self::error('journal_integrity');$hash=$record['event_sha256']??'';unset($record['event_sha256']);if(!hash_equals($hash,hash('sha256',Validator::canonicalJson($record))))return self::error('journal_integrity');$record['event_sha256']=$hash;$audit=$this->audited($record);if($audit instanceof WP_Error)return $audit;$previous=$record;}return $previous;
    }
    private function append(string $shop,string $key,?array $old,array $record):array|WP_Error
    {
        unset($record['event_sha256']);$record['sequence']=$old===null?0:$old['sequence']+1;$record['previous_event_sha256']=$old['event_sha256']??'ROOT';$record['event_sha256']=hash('sha256',Validator::canonicalJson($record));$saved=$this->insert($this->prefix($shop,$key).sprintf('%02d',$record['sequence']),$record);if($saved instanceof WP_Error)return $saved;if(!Logger::write('transformation_state_recorded',['state'=>$record['state'],'sequence'=>$record['sequence'],'event_sha256'=>$record['event_sha256'],'commercial_certified'=>false,'external_execution_authorized'=>false],'transformation_job',$record['job_hash']))return self::error('audit_persistence_uncertain');return $saved;
    }
    private function audited(array $record):true|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$contexts=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s AND context LIKE %s LIMIT 2','transformation_state_recorded','transformation_job',$record['job_hash'],'%'.$wpdb->esc_like($record['event_sha256']).'%'));
        if($wpdb->last_error!==''||!is_array($contexts)||count($contexts)!==1)return self::error('audit_persistence_uncertain');$context=json_decode($contexts[0],true);return is_array($context)&&($context['event_sha256']??null)===$record['event_sha256']&&($context['state']??null)===$record['state']&&($context['sequence']??null)===$record['sequence']?true:self::error('audit_persistence_uncertain');
    }
    private function option(string $key):array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$value=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return self::error('evidence_unavailable');if($value===null)return null;$record=json_decode($value,true);return is_array($record)?$record:self::error('evidence_corrupt');
    }
    private function insert(string $key,array $record):array|WP_Error
    {
        global $wpdb;$json=Validator::canonicalJson($record);if(strlen($json)>65536)return self::error('evidence_limit');$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>$json,'autoload'=>'no']);$stored=$this->option($key);return is_array($stored)&&Validator::canonicalJson($stored)===$json?$record:self::error('confirmation_uncertain');
    }
    private static function error(string $code):WP_Error{return new WP_Error('transformation_'.$code,'Transformation evidence is missing, stale, conflicting or outside the approved local boundary.',['status'=>409,'retry_permitted'=>false,'production_ready'=>false,'external_execution_authorized'=>false]);}
}
