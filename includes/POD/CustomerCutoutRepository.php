<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use DigiForge\ProductFactory\AssetStorage;
use DigiForge\Security\Logger;
use WP_Error;

/** Order/shop-scoped manual or offline selected segmentation. No inferred consent or live provider. */
final class CustomerCutoutRepository
{
    public function ingest(string $shop,string $key,int $orderId,string $sourcePath,?string $maskPath,array $rights,string $style,array $options):array|WP_Error
    {
        return $this->ingestInternal($shop,$key,$orderId,$sourcePath,$maskPath,$rights,$style,$options,null);
    }

    public function segment(string $shop,string $key,int $orderId,string $sourcePath,array $rights,string $style,array $options,array $selection):array|WP_Error
    {
        $scope=$this->scope($shop,$orderId);if($scope instanceof WP_Error)return $scope;
        if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$key)||!in_array($style,CutoutStyleAdapter::STYLES,true)||!in_array($rights['segmentation_method']??null,[OfflineCutoutPipeline::METHOD,ExperimentalMattingPipeline::METHOD],true))return self::error('segmentation_inputs_required');
        $checked=$this->rights($rights);if($checked instanceof WP_Error)return $checked;
        if(str_contains($sourcePath,'://')||str_contains($sourcePath,"\0")||is_link($sourcePath)||!is_file($sourcePath))return self::error('local_source_required');
        $bytes=@file_get_contents($sourcePath,false,null,0,4500001);if(!is_string($bytes)||strlen($bytes)>4500000)return self::error('bounded_source_required');
        try{$normalized=OfflineCutoutPipeline::normalizePhoto($bytes);$segmented=($rights['segmentation_method']===ExperimentalMattingPipeline::METHOD)?ExperimentalMattingPipeline::segment($normalized['png'],$selection):OfflineCutoutPipeline::segment($normalized['png'],$selection);$segmented['provenance']['normalization']=$normalized['provenance'];}catch(\Throwable){return self::error('segmentation_unavailable','Offline photo normalization/segmentation unavailable or unsafe; supply a separately reviewed alpha mask.');}
        $path=tempnam(sys_get_temp_dir(),'df-auto-mask-');$normalizedPath=tempnam(sys_get_temp_dir(),'df-normalized-photo-');
        if($path===false||$normalizedPath===false){if(is_string($path))unlink($path);if(is_string($normalizedPath))unlink($normalizedPath);return self::error('storage_unavailable');}
        try{if(file_put_contents($path,$segmented['mask_png'],LOCK_EX)!==strlen($segmented['mask_png'])||file_put_contents($normalizedPath,$segmented['source_png']??$normalized['png'],LOCK_EX)!==strlen($segmented['source_png']??$normalized['png']))return self::error('storage_uncertain');if($rights['segmentation_method']===ExperimentalMattingPipeline::METHOD){$originalPath=tempnam(sys_get_temp_dir(),'df-original-photo-');if($originalPath===false)return self::error('storage_unavailable');try{if(file_put_contents($originalPath,$normalized['png'])!==strlen($normalized['png']))return self::error('storage_uncertain');$original=(new RenderArtifactVerifier())->readArtifact($originalPath);if($original instanceof WP_Error)return $original;$original=(new RenderArtifactStore())->retain($originalPath,$original,$shop);if($original instanceof WP_Error)return $original;$segmented['provenance']['original_source']=$original;}finally{unlink($originalPath);}}return $this->ingestInternal($shop,$key,$orderId,$normalizedPath,$path,$rights,$style,$options,$segmented['provenance']);}finally{unlink($path);unlink($normalizedPath);}
    }

    private function ingestInternal(string $shop,string $key,int $orderId,string $sourcePath,?string $maskPath,array $rights,string $style,array $options,?array $segmentation):array|WP_Error
    {
        $scope=$this->scope($shop,$orderId);if($scope instanceof WP_Error)return $scope;
        if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$key))return self::error('key_invalid');
        $photo=$style==='SOURCE_PHOTO';if(($photo&&($maskPath!==null||$options!==[]||($rights['segmentation_method']??null)!=='original_bytes'))||(!$photo&&($maskPath===null||($rights['segmentation_method']??null)!==($segmentation===null?'manual_reviewed_alpha':(($segmentation['experimental']??false)?ExperimentalMattingPipeline::METHOD:OfflineCutoutPipeline::METHOD)))))return self::error('source_mode_conflict');
        $checked=$this->rights($rights);if($checked instanceof WP_Error)return $checked;
        $store=new RenderArtifactStore();$source=(new RenderArtifactVerifier())->readArtifact($sourcePath);$mask=$photo?null:(new RenderArtifactVerifier())->readArtifact($maskPath);
        foreach($photo?[$source]:[$source,$mask]as $image)if($image instanceof WP_Error)return $image;elseif($image['mime']!=='image/png')return self::error('png_required');
        if($segmentation!==null){try{if($rights['segmentation_method']===ExperimentalMattingPipeline::METHOD)ExperimentalMattingPipeline::assertProvenance($segmentation,$source,$mask);else OfflineCutoutPipeline::assertProvenance($segmentation,$source,$mask);}catch(\Throwable){return self::error('segmentation_provenance_conflict');}}
        $source=$store->retain($sourcePath,$source,$shop);if($source instanceof WP_Error)return $source;if(!$photo){$mask=$store->retain($maskPath,$mask,$shop);if($mask instanceof WP_Error)return $mask;}
        try{$bytes=$photo?$this->bytes($source):CutoutStyleAdapter::apply($this->bytes($source),$this->bytes($mask),$style,$options);}catch(\Throwable $e){return self::error('unsupported',$e->getMessage());}
        $temporary=tempnam(sys_get_temp_dir(),'df-cutout-');if($temporary===false)return self::error('storage_unavailable');
        try{if(file_put_contents($temporary,$bytes)!==strlen($bytes))return self::error('storage_uncertain');$result=(new RenderArtifactVerifier())->readArtifact($temporary);if($result instanceof WP_Error)return $result;$result=$store->retain($temporary,$result,$shop);}finally{unlink($temporary);}
        if($result instanceof WP_Error)return $result;
        $proof=['ingestion_key'=>$key,'version'=>CutoutStyleAdapter::VERSION,'shop_key'=>$shop,'order_id'=>$orderId,'source'=>$source,'mask'=>$mask,'result'=>$result,'rights'=>$rights,'style'=>$style,'options'=>$options,'state'=>'REVIEW_REQUIRED','commercial_certified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false];
        if($segmentation!==null)$proof['segmentation']=$segmentation;
        $proof['sha256']=hash('sha256',Validator::canonicalJson($proof));$name=$this->key($shop,$key);$old=$this->option($name);if($old instanceof WP_Error)return $old;
        if($old!==null)return Validator::canonicalJson($old)===Validator::canonicalJson($proof)?$this->read($shop,$key):self::error('idempotency_conflict');
        $index=$this->option('df_cutout_result_'.hash('sha256',$shop.'|'.$orderId.'|'.$result['storage_reference']));if($index instanceof WP_Error)return $index;if($index!==null)return self::error('review_rebind_conflict','These exact order/artifact bytes already have an immutable reviewed provenance; reuse that receipt.');
        $saved=$this->put($name,$proof);if($saved instanceof WP_Error)return $saved;
        if(!Logger::write('customer_cutout_ingested',['sha256'=>$proof['sha256'],'synthetic'=>$rights['evidence_kind']==='SYNTHETIC_TEST_ONLY','external_execution_authorized'=>false],'customer_cutout',$proof['sha256']))return self::error('audit_uncertain');return $saved;
    }

    public function approve(string $shop,string $key,array $review):array|WP_Error
    {
        $record=$this->read($shop,$key);if($record instanceof WP_Error)return $record;
        $flags=$this->reviewFlags($record);if(($record['segmentation']['selection_boundary_contact']??false)===true)return self::error('selection_clipped','Selected region clips the segmented subject; revise selection or use reviewed alpha fallback.');
        if(count($review)!==count($flags)||array_diff(array_keys($review),$flags))return self::error('review_required');foreach($review as $flag)if($flag!==false)return self::error('manual_review_failed');
        $qa=$this->qa($record);if($qa instanceof WP_Error)return $qa;
        $independent=null;if($record['style']!=='SOURCE_PHOTO'){$independent=(new CutoutVisualReviewRepository())->assertApproved($record);if($independent instanceof WP_Error)return $independent;if($independent['reviewer_id']!==get_current_user_id()||Validator::canonicalJson($independent['flags'])!==Validator::canonicalJson($review))return self::error('independent_review_required');}
        $name=($record['style']==='SOURCE_PHOTO'?'df_cutout_review_qa_v1_':'df_cutout_review_independent_v1_').$record['sha256'];$old=$this->option($name);if($old instanceof WP_Error)return $old;
        if($old===null){$approval=['cutout_sha256'=>$record['sha256'],'review'=>$review,'visual_qa'=>$qa,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('Y-m-d H:i:s'),'commercial_certified'=>false];if($independent!==null)$approval['independent_review_sha256']=$independent['sha256'];$approval['sha256']=hash('sha256',Validator::canonicalJson($approval));$old=$this->put($name,$approval);if($old instanceof WP_Error)return $old;
            if(!Logger::write('customer_cutout_reviewed',['approval_sha256'=>$approval['sha256'],'external_execution_authorized'=>false],'customer_cutout',$record['sha256']))return self::error('audit_uncertain');}
        $verified=$this->approval($record);if($verified instanceof WP_Error)return $verified;
        $index='df_cutout_result_'.hash('sha256',$shop.'|'.$record['order_id'].'|'.$record['result']['storage_reference']);$saved=$this->put($index,['key'=>$key,'sha256'=>$record['sha256']]);return $saved instanceof WP_Error?$saved:$record+['review'=>$verified,'review_state'=>'APPROVED_LOCAL_ONLY'];
    }

    /** Authenticated evidence from actual retained pixels, never caller measurements. */
    public function visualQa(string $shop,string $key):array|WP_Error
    {
        $record=$this->read($shop,$key);return $record instanceof WP_Error?$record:$this->qa($record);
    }
    public function visualPreviews(string $shop,string $key):array|WP_Error
    {
        $record=$this->read($shop,$key);if($record instanceof WP_Error)return $record;
        try{return CutoutVisualQa::previews($this->bytes($record['result']));}catch(\Throwable){return self::error('qa_unavailable');}
    }
    private function qa(array $record):array|WP_Error
    {
        try{
            if($record['style']==='SOURCE_PHOTO')return ['version'=>CutoutVisualQa::VERSION,'state'=>'NOT_A_CUTOUT','cutout_sha256'=>$record['sha256'],'commercial_certified'=>false];
            $mask=CutoutVisualQa::measure($this->bytes($record['mask']));$result=CutoutVisualQa::measure($this->bytes($record['result']));
            if($mask['critical_defects']!==[]||$result['critical_defects']!==[])return self::error('qa_critical_defect','Empty, opaque or canvas-clipped cutouts cannot be approved.');
            $previews=CutoutVisualQa::previews($this->bytes($record['result']));
            $qa=['version'=>CutoutVisualQa::VERSION,'cutout_sha256'=>$record['sha256'],'source_sha256'=>$record['source']['sha256'],'mask'=>$mask,'result'=>$result,'preview_sha256'=>array_map(static fn(string $png):string=>hash('sha256',$png),$previews),'state'=>'OPERATOR_REVIEW_REQUIRED','commercial_certified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false];
            $qa['sha256']=hash('sha256',Validator::canonicalJson($qa));return $qa;
        }catch(\Throwable){return self::error('qa_unavailable');}
    }

    public function read(string $shop,string $key):array|WP_Error
    {
        if($shop!=='personalized_pod'||!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required');
        $record=$this->option($this->key($shop,$key));if($record instanceof WP_Error)return $record;if(!is_array($record))return self::error('not_found');
        if(!is_string($record['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$record['sha256'])||($record['version']??null)!==CutoutStyleAdapter::VERSION||($record['ingestion_key']??null)!==$key||!is_int($record['order_id']??null)||!is_array($record['rights']??null)||!is_array($record['options']??null)||!is_array($record['source']??null)||!is_array($record['result']??null)||!in_array($record['style']??null,array_merge(CutoutStyleAdapter::STYLES,['SOURCE_PHOTO']),true)||($record['style']!=='SOURCE_PHOTO'&&!is_array($record['mask']??null))||($record['state']??null)!=='REVIEW_REQUIRED'||($record['commercial_certified']??null)!==false||($record['supplier_certified']??null)!==false||($record['external_execution_authorized']??null)!==false)return self::error('store_corrupt');
        $scope=$this->scope($shop,(int)($record['order_id']??0));if($scope instanceof WP_Error)return $scope;
        $hash=$record['sha256']??'';$proof=$record;unset($proof['sha256']);if(($record['shop_key']??null)!==$shop||!hash_equals($hash,hash('sha256',Validator::canonicalJson($proof))))return self::error('provenance_conflict');
        foreach($record['style']==='SOURCE_PHOTO'?['source','result']:['source','mask','result']as $field){$v=(new RenderArtifactStore())->verify($record[$field]);if($v instanceof WP_Error)return $v;if(!str_contains($v['storage_reference'],'/shop-'.$shop.'/'))return self::error('scope_conflict');}
        if(!$this->audited('customer_cutout_ingested',$record['sha256'],'sha256',$record['sha256']))return self::error('audit_uncertain');
        $r=$this->rights($record['rights']);if($r instanceof WP_Error)return $r;
        if(in_array($record['rights']['segmentation_method']??null,[OfflineCutoutPipeline::METHOD,ExperimentalMattingPipeline::METHOD],true)){try{if(!is_array($record['segmentation']??null))return self::error('segmentation_provenance_required');if($record['rights']['segmentation_method']===ExperimentalMattingPipeline::METHOD){$original=$record['segmentation']['original_source']??null;if(!is_array($original)||(new RenderArtifactStore())->verify($original) instanceof WP_Error||!str_contains($original['storage_reference'],'/shop-'.$shop.'/'))return self::error('original_source_conflict');ExperimentalMattingPipeline::assertProvenance($record['segmentation'],$record['source'],$record['mask']);}else OfflineCutoutPipeline::assertProvenance($record['segmentation'],$record['source'],$record['mask']);}catch(\Throwable){return self::error('segmentation_provenance_conflict');}}elseif(isset($record['segmentation']))return self::error('segmentation_mode_conflict');
        try{$bytes=$record['style']==='SOURCE_PHOTO'?$this->bytes($record['source']):CutoutStyleAdapter::apply($this->bytes($record['source']),$this->bytes($record['mask']),$record['style'],$record['options']);}catch(\Throwable){return self::error('reproduction_failed');}
        return hash_equals($record['result']['sha256'],hash('sha256',$bytes))?$record:self::error('reproduction_failed');
    }

    public function assertForOrder(string $shop,int $orderId,string $reference):array|WP_Error
    {
        $scope=$this->scope($shop,$orderId);if($scope instanceof WP_Error)return $scope;
        $index=$this->option('df_cutout_result_'.hash('sha256',$shop.'|'.$orderId.'|'.$reference));if($index instanceof WP_Error)return $index;if(!is_array($index)||!is_string($index['key']??null)||!is_string($index['sha256']??null))return self::error('approved_cutout_required');
        $record=$this->read($shop,$index['key']);if($record instanceof WP_Error)return $record;if($record['order_id']!==$orderId||$record['result']['storage_reference']!==$reference||$record['sha256']!==$index['sha256'])return self::error('scope_conflict');
        $approval=$this->approval($record);return $approval instanceof WP_Error?$approval:$record;
    }

    private function approval(array $record):array|WP_Error
    {
        if(($record['segmentation']['selection_boundary_contact']??false)===true)return self::error('selection_clipped');
        $a=$this->option(($record['style']==='SOURCE_PHOTO'?'df_cutout_review_qa_v1_':'df_cutout_review_independent_v1_').$record['sha256']);if($a instanceof WP_Error)return $a;if(!is_array($a)||($a['reviewed_by']??0)<1||($a['cutout_sha256']??null)!==$record['sha256'])return self::error('approved_cutout_required');$h=$a['sha256']??'';if(!is_string($h)||!preg_match('/^[a-f0-9]{64}$/D',$h)||!is_array($a['review']??null)||count($a['review'])!==count($this->reviewFlags($record))||array_diff(array_keys($a['review']),$this->reviewFlags($record))||array_filter($a['review'],static fn($value):bool=>$value!==false))return self::error('review_conflict');$p=$a;unset($p['sha256']);if(!hash_equals($h,hash('sha256',Validator::canonicalJson($p))))return self::error('review_conflict');
        $qa=$this->qa($record);if($qa instanceof WP_Error)return $qa;if(!is_array($a['visual_qa']??null)||Validator::canonicalJson($qa)!==Validator::canonicalJson($a['visual_qa']))return self::error('qa_review_conflict','Current measured pixel QA must be bound to this exact operator approval.');
        if($record['style']!=='SOURCE_PHOTO'){$independent=(new CutoutVisualReviewRepository())->assertApproved($record);if($independent instanceof WP_Error)return $independent;if(($a['independent_review_sha256']??null)!==$independent['sha256']||($a['reviewed_by']??null)!==$independent['reviewer_id']||Validator::canonicalJson($a['review'])!==Validator::canonicalJson($independent['flags']))return self::error('independent_review_changed');}
        return $this->audited('customer_cutout_reviewed',$record['sha256'],'approval_sha256',$h)?$a:self::error('audit_uncertain');
    }
    private function audited(string $event,string $object,string $field,string $sha):bool
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s',$event,'customer_cutout',$object));if($wpdb->last_error!==''||!is_array($rows))return false;foreach($rows as $row)if((json_decode($row,true)[$field]??null)===$sha)return true;return false;
    }
    private function scope(string $shop,int $orderId):true|WP_Error
    {
        if($orderId<1||$shop!=='personalized_pod'||!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required');global $wpdb;$predicate=\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o');$wpdb->last_error='';$order=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.Tables::orders().' o WHERE id=%d AND '.$predicate,$orderId),ARRAY_A);return $wpdb->last_error===''&&is_array($order)?true:self::error('order_scope_required');
    }
    private function rights(array $rights):true|WP_Error
    {
        $keys=['rights_reference','rights_sha256','consent_reference','consent_sha256','retention_reference','retention_sha256','segmentation_method','evidence_kind'];if(count($rights)!==count($keys)||array_diff(array_keys($rights),$keys)||!in_array($rights['segmentation_method']??null,['manual_reviewed_alpha','original_bytes',OfflineCutoutPipeline::METHOD,ExperimentalMattingPipeline::METHOD],true)||!in_array($rights['evidence_kind']??null,['OWNER_SUPPLIED','SYNTHETIC_TEST_ONLY'],true))return self::error('rights_required');
        foreach(['rights','consent','retention']as $kind){$reference=$rights[$kind.'_reference'];$hash=$rights[$kind.'_sha256'];if(!is_string($reference)||strlen($reference)>512||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('rights_required');$path=AssetStorage::absolutePath($reference);if((str_contains($reference,'/shop-')&&!str_contains($reference,'/shop-personalized_pod/'))||$path===null||!is_file($path)||filesize($path)<1||filesize($path)>65536||!hash_equals($hash,hash_file('sha256',$path)))return self::error('rights_evidence_changed');}return true;
    }
    public function requiredReviewFlags(array $record):array {return $this->reviewFlags($record);}
    private function reviewFlags(array $record):array {return array_merge(['missing_head','clipped_features','halo','low_contrast','fragmented_mask','low_resolution'],array_merge(isset($record['segmentation'])?['blurred_features','missing_hair_ears_whiskers']:[],($record['segmentation']['experimental']??false)?['background_contamination','unnatural_soft_alpha','foreground_color_damage']:[]));}
    private function bytes(array $image):string { $path=AssetStorage::absolutePath($image['storage_reference']);if($path===null)throw new \InvalidArgumentException('Stored cutout bytes unavailable.');$bytes=file_get_contents($path);if(!is_string($bytes)||!hash_equals($image['sha256'],hash('sha256',$bytes)))throw new \InvalidArgumentException('Stored cutout changed.');return $bytes; }
    private function key(string $shop,string $key):string{return 'df_cutout_'.hash('sha256',$shop.'|'.$key);}
    private function option(string $name):array|WP_Error|null{global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));if($wpdb->last_error!=='')return self::error('store_unavailable');if($raw===null)return null;if(!is_string($raw)||strlen($raw)>65536)return self::error('store_corrupt');$r=json_decode($raw,true);return is_array($r)?$r:self::error('store_corrupt');}
    private function put(string $name,array $record):array|WP_Error{global $wpdb;$old=$this->option($name);if($old instanceof WP_Error)return $old;if($old!==null)return Validator::canonicalJson($old)===Validator::canonicalJson($record)?$old:self::error('immutable_conflict');$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>Validator::canonicalJson($record),'autoload'=>'no']);$saved=$this->option($name);return is_array($saved)&&Validator::canonicalJson($saved)===Validator::canonicalJson($record)?$saved:self::error('store_uncertain');}
    private static function error(string $code,string $message='Exact reviewed cutout evidence required.'):WP_Error{return new WP_Error('customer_cutout_'.$code,$message,['status'=>409,'external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
