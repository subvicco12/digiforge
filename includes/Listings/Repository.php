<?php

declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function createListing(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $pv=absint($input['product_version_id']??0); if($pv<1||!$this->exists(Tables::product_versions(),$pv)) return $this->error('invalid_parent','Valid product_version_id is required.');
            $environment=Validator::environment(sanitize_key((string)($input['environment']??''))); $channel=Validator::channel(sanitize_key((string)($input['channel']??'etsy')));
            $title=Validator::title((string)($input['title']??'')); $currency=Validator::currency((string)($input['currency']??'USD')); $price=Validator::price($input['price_amount']??0);
            $taxonomy=Validator::structured((array)($input['taxonomy_metadata']??[])); $quantity=Validator::structured((array)($input['quantity_policy']??[]));
        } catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        return $this->insert(Tables::listings(),$key,['product_version_id'=>$pv,'channel'=>$channel,'environment'=>$environment,'shop_reference'=>sanitize_text_field((string)($input['shop_reference']??'')),'title'=>$title,'description'=>sanitize_textarea_field((string)($input['description']??'')),'taxonomy_metadata'=>Validator::canonicalJson($taxonomy),'price_amount'=>$price,'currency'=>$currency,'quantity_policy'=>Validator::canonicalJson($quantity),'personalization_enabled'=>!empty($input['personalization_enabled'])?1:0,'state'=>'DRAFT','approved_by'=>0,'approved_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'listing');
    }

    public function setSeo(array $input,?string $key=null): array|WP_Error
    {
        $listingId=absint($input['listing_id']??0);if(!$this->exists(Tables::listings(),$listingId))return $this->error('invalid_parent','Valid listing_id is required.');
        try{$parts=[];foreach(['tags','keywords','materials','attributes','audience_metadata','evidence'] as $field)$parts[$field]=Validator::structured((array)($input[$field]??[]),$field==='tags'?20:100);}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        return $this->insert(Tables::listing_seo(),$key,['listing_id'=>$listingId,'tags'=>Validator::canonicalJson($parts['tags']),'keywords'=>Validator::canonicalJson($parts['keywords']),'materials'=>Validator::canonicalJson($parts['materials']),'attributes'=>Validator::canonicalJson($parts['attributes']),'audience_metadata'=>Validator::canonicalJson($parts['audience_metadata']),'evidence'=>Validator::canonicalJson($parts['evidence']),'canonical_hash'=>hash('sha256',Validator::canonicalJson($parts)),'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'listing_seo');
    }

    public function bindMedia(array $input,?string $key=null): array|WP_Error
    {
        $listing=$this->find(Tables::listings(),absint($input['listing_id']??0));if(!is_array($listing))return $this->error('invalid_parent','Valid listing_id is required.');
        $revisionId=absint($input['asset_revision_id']??0);$bundleId=absint($input['release_bundle_id']??0);if($revisionId<1&&$bundleId<1)return $this->error('validation','An approved media source is required.');
        if($bundleId>0&&!$this->bundleBelongsToProduct($bundleId,(int)$listing['product_version_id'],['APPROVED','RELEASE_READY']))return $this->error('invalid_media','Release bundle must belong to the listing product through its production plan and be approved.',409);
        if($revisionId>0){$revision=$this->find(Tables::asset_revisions(),$revisionId);if(!is_array($revision)||(string)($revision['state']??'')!=='APPROVED')return $this->error('invalid_media','Asset revision must exist and be approved.',409);$spec=$this->find(Tables::asset_specs(),(int)($revision['asset_spec_id']??0));if(!is_array($spec)||(int)($spec['product_version_id']??0)!==(int)$listing['product_version_id'])return $this->error('invalid_media','Asset revision must belong to the listing product version.',409);}
        return $this->insert(Tables::listing_media(),$key,['listing_id'=>(int)$listing['id'],'asset_revision_id'=>$revisionId,'release_bundle_id'=>$bundleId,'media_role'=>sanitize_key((string)($input['media_role']??'image')),'position_index'=>min(100,absint($input['position_index']??0)),'state'=>'BOUND','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'listing_media');
    }

    public function bindPod(array $input,?string $key=null): array|WP_Error
    {
        $listing=$this->find(Tables::listings(),absint($input['listing_id']??0));$mapping=$this->find(Tables::pod_mappings(),absint($input['provider_mapping_id']??0));if(!is_array($listing)||!is_array($mapping)||(int)$listing['product_version_id']!==(int)$mapping['product_version_id'])return $this->error('invalid_relationship','Listing and POD mapping must share a product version.',409);
        if((string)$listing['environment']!==(string)$mapping['environment']||(string)$mapping['state']!=='APPROVED')return $this->error('pod_not_ready','POD mapping must be approved in the same environment.',409);
        $schemaId=absint($input['personalization_schema_id']??0);if($schemaId>0){$schema=$this->find(Tables::personalization_schemas(),$schemaId);if(!is_array($schema)||(int)$schema['product_version_id']!==(int)$listing['product_version_id']||(string)$schema['state']!=='APPROVED')return $this->error('personalization_not_ready','Personalization schema must be approved for the same product.',409);}
        $readiness=(new \DigiForge\POD\Repository())->readiness((int)$mapping['id']);if(is_wp_error($readiness)||empty($readiness['ready']))return $this->error('pod_not_ready','POD readiness must pass before binding.',409);
        return $this->insert(Tables::listing_pod_bindings(),$key,['listing_id'=>(int)$listing['id'],'provider_mapping_id'=>(int)$mapping['id'],'personalization_schema_id'=>$schemaId,'readiness_hash'=>hash('sha256',Validator::canonicalJson($readiness)),'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'listing_pod_binding');
    }

    public function createIntent(array $input,?string $key=null): array|WP_Error
    {
        $listing=$this->find(Tables::listings(),absint($input['listing_id']??0));if(!is_array($listing))return $this->error('invalid_parent','Valid listing_id is required.');$type=strtoupper(sanitize_key((string)($input['intent_type']??'')));if(!in_array($type,['PREPARE_DRAFT','PREPARE_MEDIA','PREPARE_INVENTORY','PREPARE_PERSONALIZATION','PREPARE_UPDATE'],true))return $this->error('validation','Invalid Etsy intent type.');
        $packageId=absint($input['draft_package_id']??0);if($packageId>0){$package=$this->find(Tables::etsy_draft_packages(),$packageId);if(!is_array($package)||(int)$package['listing_id']!==(int)$listing['id'])return $this->error('invalid_relationship','Draft package must belong to the listing.',409);}
        try{$payload=Validator::structured((array)($input['input_payload']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        return $this->insert(Tables::etsy_intents(),$key,['listing_id'=>(int)$listing['id'],'draft_package_id'=>$packageId,'environment'=>(string)$listing['environment'],'intent_type'=>$type,'input_payload'=>Validator::canonicalJson($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'etsy_intent');
    }

    public function transition(string $entity,int $id,string $to): array|WP_Error
    {
        $table=$entity==='listing'?Tables::listings():($entity==='intent'?Tables::etsy_intents():'');if($table==='')return $this->error('validation','Unknown lifecycle entity.');$row=$this->find($table,$id);$to=strtoupper(sanitize_key($to));if(!is_array($row))return $this->error('not_found','Listing record not found.',404);if((string)$row['state']===$to)return $row+['idempotent_transition'=>true];if(!Lifecycle::can($entity,(string)$row['state'],$to))return $this->error('invalid_transition','Lifecycle transition is not permitted.',409);if(($to==='APPROVED'||$to==='APPROVED_INTENT')&&get_current_user_id()<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);if($entity==='listing'&&$to==='APPROVED')return $this->error('gate3_review_required','Listing approval must be recorded through a pending Gate 3 readiness review.',409);
        global $wpdb;$data=['state'=>$to,'updated_at'=>$this->now()];if($entity==='listing'&&$to==='APPROVED'){$data['approved_by']=get_current_user_id();$data['approved_at']=$this->now();}$ok=$wpdb->update($table,$data,['id'=>$id,'state'=>(string)$row['state']]);if($ok!==1)return $this->error('transition_conflict','State changed concurrently or update failed.',409);Logger::audit('listing_state_changed',['entity'=>$entity,'from'=>$row['state'],'to'=>$to],$entity,(string)$id);return $this->find($table,$id)?:[];
    }

    public function readiness(int $listingId): array|WP_Error
    {
        $listing=$this->find(Tables::listings(),$listingId);if(!is_array($listing))return $this->error('not_found','Listing not found.',404);global $wpdb;$pv=(int)$listing['product_version_id'];$count=function(string $sql)use($wpdb):int|WP_Error{$wpdb->last_error='';$raw=$wpdb->get_var($sql);if(!empty($wpdb->last_error)||!is_numeric($raw))return $this->error('readiness_evidence_unavailable','Listing readiness evidence is unavailable; release readiness is blocked.',503);return(int)$raw;};$seo=$count($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listing_seo().' WHERE listing_id=%d',$listingId));if(is_wp_error($seo))return $seo;$wpdb->last_error='';$mediaRows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::listing_media().' WHERE listing_id=%d ORDER BY position_index ASC,id ASC',$listingId),ARRAY_A);if(!is_array($mediaRows)||!empty($wpdb->last_error))return $this->error('readiness_evidence_unavailable','Listing readiness evidence is unavailable; release readiness is blocked.',503);
        $digitalCount=$count($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::digital_products().' WHERE product_version_id=%d',$pv));if(is_wp_error($digitalCount))return $digitalCount;$digital=$digitalCount>0;$podMappings=$count($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::pod_mappings()." WHERE product_version_id=%d AND environment=%s AND state='APPROVED'",$pv,(string)$listing['environment']));if(is_wp_error($podMappings))return $podMappings;$wpdb->last_error='';$podBindings=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::listing_pod_bindings().' WHERE listing_id=%d',$listingId),ARRAY_A);if(!is_array($podBindings)||!empty($wpdb->last_error))return $this->error('readiness_evidence_unavailable','Listing readiness evidence is unavailable; release readiness is blocked.',503);$pod=$podMappings>0||$podBindings!==[];
        $mediaValid=$mediaRows!==[];foreach($mediaRows as $media){$sourceValid=false;$revisionId=(int)($media['asset_revision_id']??0);$bundleId=(int)($media['release_bundle_id']??0);if($revisionId>0){$revision=$this->find(Tables::asset_revisions(),$revisionId);$spec=is_array($revision)?$this->find(Tables::asset_specs(),(int)($revision['asset_spec_id']??0)):null;$sourceValid=is_array($revision)&&is_array($spec)&&(string)($revision['state']??'')==='APPROVED'&&(int)($spec['product_version_id']??0)===$pv;}if(!$sourceValid&&$bundleId>0)$sourceValid=$this->bundleBelongsToProduct($bundleId,$pv,['RELEASE_READY']);if(!$sourceValid){$mediaValid=false;break;}}
        $podValid=!$pod;if($pod){$podValid=$podBindings!==[];foreach($podBindings as $binding){$mapping=$this->find(Tables::pod_mappings(),(int)$binding['provider_mapping_id']);if(!is_array($mapping)||(int)$mapping['product_version_id']!==$pv||(string)$mapping['environment']!==(string)$listing['environment']||(string)$mapping['state']!=='APPROVED'){$podValid=false;break;}$current=(new \DigiForge\POD\Repository())->readiness((int)$mapping['id']);if(is_wp_error($current)||empty($current['ready'])||!hash_equals((string)($binding['readiness_hash']??''),hash('sha256',Validator::canonicalJson($current)))){$podValid=false;break;}}}
        $productMode=$digital&&$pod?'hybrid':($pod?'pod':'digital');$checks=['listing_approved'=>(string)$listing['state']==='APPROVED','seo_present'=>$seo>0,'approved_media_bound'=>$mediaValid,'pod_binding_valid'=>$podValid,'human_approval'=>(int)$listing['approved_by']>0&&!empty($listing['approved_at'])];$ready=!in_array(false,$checks,true);$payload=['listing_id'=>$listingId,'product_mode'=>$productMode,'ready'=>$ready,'checks'=>$checks];$payload['hash']=hash('sha256',Validator::canonicalJson($payload));return $payload;
    }

    public function createReadinessReview(int $listingId, ?string $key=null): array|WP_Error
    {
        $listing=$this->find(Tables::listings(),$listingId);
        if(!is_array($listing))return $this->error('not_found','Listing not found.',404);
        if((string)($listing['state']??'')!=='REVIEW_REQUIRED')return $this->error('review_state','Listing must be REVIEW_REQUIRED before Gate 3 review evidence is created.',409);
        $readiness=$this->readiness($listingId);
        if(is_wp_error($readiness))return $readiness;
        $checks=(array)($readiness['checks']??[]);
        foreach(['seo_present','approved_media_bound','pod_binding_valid'] as $required){
            if(empty($checks[$required]))return $this->error('review_not_ready','Listing prerequisites must pass before Gate 3 review.',409);
        }
        $canonical=Validator::canonicalJson($readiness);
        return $this->insert(Tables::listing_readiness_reviews(),$key,[
            'listing_id'=>$listingId,'readiness'=>$canonical,'readiness_hash'=>(string)($readiness['hash']??hash('sha256',$canonical)),
            'decision'=>'PENDING','reviewed_by'=>0,'reviewed_at'=>null,'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'listing_readiness_review');
    }

    public function createLegacyReadinessReview(int $listingId, ?string $key=null): array|WP_Error
    {
        global $wpdb;
        if($wpdb->query('START TRANSACTION')===false)return $this->error('database_error','Legacy Gate 3 repair could not start a transaction.',500);
        $locked=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d FOR UPDATE',$listingId),ARRAY_A);
        if(!empty($wpdb->last_error)||!is_array($locked)){$wpdb->query('ROLLBACK');return $this->error('not_found','Listing not found or could not be locked.',404);}
        if((string)($locked['state']??'')!=='APPROVED'){$wpdb->query('ROLLBACK');return $this->error('legacy_repair_state','Legacy Gate 3 repair applies only to an already APPROVED listing.',409);}
        $existing=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::listing_readiness_reviews().' WHERE listing_id=%d',$listingId));
        if(!empty($wpdb->last_error)||!is_numeric($existing)){$wpdb->query('ROLLBACK');return $this->error('readiness_evidence_unavailable','Listing review evidence is unavailable; legacy repair is blocked.',503);}
        if((int)$existing>0){$wpdb->query('ROLLBACK');return $this->error('legacy_repair_not_required','Listing already has Gate 3 readiness review evidence.',409);}
        $before=$this->readiness($listingId);
        if(is_wp_error($before)){$wpdb->query('ROLLBACK');return $before;}
        $checks=(array)($before['checks']??[]);
        foreach(['seo_present','approved_media_bound','pod_binding_valid'] as $required){
            if(empty($checks[$required])){$wpdb->query('ROLLBACK');return $this->error('review_not_ready','Current listing prerequisites must pass before legacy Gate 3 repair.',409);}
        }
        $listingUpdated=$wpdb->update(Tables::listings(),['state'=>'REVIEW_REQUIRED','approved_by'=>0,'approved_at'=>null,'updated_at'=>$this->now()],['id'=>$listingId,'state'=>'APPROVED']);
        if($listingUpdated!==1){$wpdb->query('ROLLBACK');return $this->error('transition_conflict','Listing state changed concurrently or could not enter legacy Gate 3 review.',409);}
        $readiness=$this->readiness($listingId);
        if(is_wp_error($readiness)){$wpdb->query('ROLLBACK');return $readiness;}
        $postChecks=(array)($readiness['checks']??[]);
        foreach(['seo_present','approved_media_bound','pod_binding_valid'] as $required){
            if(empty($postChecks[$required])){$wpdb->query('ROLLBACK');return $this->error('review_not_ready','Listing prerequisites changed during legacy Gate 3 repair.',409);}
        }
        $canonical=Validator::canonicalJson($readiness);
        $review=$this->insert(Tables::listing_readiness_reviews(),$key,[
            'listing_id'=>$listingId,'readiness'=>$canonical,'readiness_hash'=>(string)($readiness['hash']??hash('sha256',$canonical)),
            'decision'=>'PENDING','reviewed_by'=>0,'reviewed_at'=>null,'created_at'=>$this->now(),'updated_at'=>$this->now()
        ],'listing_readiness_review');
        if(is_wp_error($review)){$wpdb->query('ROLLBACK');return $review;}
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return $this->error('database_error','Legacy Gate 3 repair could not be committed.',500);}
        Logger::audit('listing_legacy_gate3_review_created',['listing_id'=>$listingId,'prior_state'=>'APPROVED','new_state'=>'REVIEW_REQUIRED'],'listing_readiness_review',(string)($review['id']??0));
        return $review+['external_actions_performed'=>false];
    }

    public function decideReadinessReview(int $reviewId,string $decision): array|WP_Error
    {
        $review=$this->find(Tables::listing_readiness_reviews(),$reviewId);
        if(!is_array($review))return $this->error('review_not_found','Listing readiness review not found.',404);
        if((string)($review['decision']??'')!=='PENDING')return $this->error('review_decided','Listing readiness review has already been decided.',409);
        if(get_current_user_id()<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);
        $decision=strtoupper(sanitize_key($decision));
        if(!in_array($decision,['APPROVED','REJECTED'],true))return $this->error('review_decision','Decision must be APPROVED or REJECTED.');
        $listingId=(int)($review['listing_id']??0);
        $listing=$this->find(Tables::listings(),$listingId);
        if(!is_array($listing)||(string)($listing['state']??'')!=='REVIEW_REQUIRED')return $this->error('review_state','Listing is no longer awaiting Gate 3 review.',409);
        if($decision==='APPROVED'){
            $current=$this->readiness($listingId);
            if(is_wp_error($current))return $current;
            $checks=(array)($current['checks']??[]);
            foreach(['seo_present','approved_media_bound','pod_binding_valid'] as $required){
                if(empty($checks[$required]))return $this->error('review_not_ready','Current listing prerequisites no longer pass.',409);
            }
        }
        $targetState=$decision==='APPROVED'?'APPROVED':'REJECTED';
        if(!Lifecycle::can('listing',(string)$listing['state'],$targetState))return $this->error('invalid_transition','Lifecycle transition is not permitted.',409);
        global $wpdb;$now=$this->now();$reviewer=get_current_user_id();
        $wpdb->query('START TRANSACTION');
        $reviewUpdated=$wpdb->update(Tables::listing_readiness_reviews(),['decision'=>$decision,'reviewed_by'=>$reviewer,'reviewed_at'=>$now,'updated_at'=>$now],['id'=>$reviewId,'decision'=>'PENDING']);
        if($reviewUpdated!==1){$wpdb->query('ROLLBACK');return $this->error('review_conflict','Listing review changed concurrently or could not be recorded.',409);}
        $listingData=['state'=>$targetState,'updated_at'=>$now];
        if($targetState==='APPROVED'){$listingData['approved_by']=$reviewer;$listingData['approved_at']=$now;}
        $listingUpdated=$wpdb->update(Tables::listings(),$listingData,['id'=>$listingId,'state'=>'REVIEW_REQUIRED']);
        if($listingUpdated!==1){$wpdb->query('ROLLBACK');return $this->error('transition_conflict','Listing state changed concurrently or could not be recorded.',409);}
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return $this->error('database_error','Gate 3 decision could not be committed.',500);}
        Logger::audit('listing_state_changed',['entity'=>'listing','from'=>'REVIEW_REQUIRED','to'=>$targetState],'listing',(string)$listingId);
        Logger::audit('listing_readiness_review_decided',['listing_id'=>$listingId,'decision'=>$decision],'listing_readiness_review',(string)$reviewId);
        return $this->find(Tables::listing_readiness_reviews(),$reviewId)?:[];
    }

    public function createDraftPackage(array $input,?string $key=null): array|WP_Error
    {
        if(get_current_user_id()<1)return $this->error('reviewer_required','Authenticated human reviewer required to create a Gate 3 draft package.',403);$listingId=absint($input['listing_id']??0);$listing=$this->find(Tables::listings(),$listingId);if(!is_array($listing))return $this->error('invalid_parent','Valid listing_id is required.');$readiness=$this->readiness($listingId);if(is_wp_error($readiness)||empty($readiness['ready']))return $this->error('not_ready','Listing is not release-ready.',409);$seo=$this->firstBy(Tables::listing_seo(),'listing_id',$listingId);$payload=['listing'=>$listing,'seo'=>$seo?:[],'readiness'=>$readiness];$canonical=Validator::canonicalJson($payload);return $this->insert(Tables::etsy_draft_packages(),$key,['listing_id'=>$listingId,'package_version'=>sanitize_text_field((string)($input['package_version']??'v1')),'canonical_payload'=>$canonical,'payload_hash'=>hash('sha256',$canonical),'readiness'=>Validator::canonicalJson($readiness),'readiness_hash'=>(string)$readiness['hash'],'approved_by'=>get_current_user_id(),'approved_at'=>$this->now(),'created_by'=>get_current_user_id(),'created_at'=>$this->now()],'etsy_draft_package');
    }

    public function list(string $entity,int $page=1,int $perPage=20): array
    {
        $tables=['listings'=>Tables::listings(),'seo'=>Tables::listing_seo(),'media'=>Tables::listing_media(),'pod'=>Tables::listing_pod_bindings(),'packages'=>Tables::etsy_draft_packages(),'intents'=>Tables::etsy_intents()];$table=$tables[$entity]??'';if($table==='')return['items'=>[],'pagination'=>['total_items'=>0,'total_pages'=>0]];global $wpdb;$page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;$wpdb->last_error='';$items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",$perPage,$offset),ARRAY_A);if(!is_array($items)||!empty($wpdb->last_error))return['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE'];$wpdb->last_error='';$total=$wpdb->get_var("SELECT COUNT(*) FROM $table");if(!is_numeric($total)||!empty($wpdb->last_error))return['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE'];$total=(int)$total;return['items'=>$items,'pagination'=>['total_items'=>$total,'total_pages'=>(int)ceil($total/$perPage)],'query_state'=>'AVAILABLE'];
    }

    private function bundleBelongsToProduct(int $bundleId,int $productVersionId,array $allowedStates): bool
    {
        $bundle=$this->find(Tables::release_bundles(),$bundleId);if(!is_array($bundle)||!in_array((string)($bundle['state']??''),$allowedStates,true))return false;$plan=$this->find(Tables::production_plans(),(int)($bundle['production_plan_id']??0));return is_array($plan)&&(int)($plan['product_version_id']??0)===$productVersionId;
    }

    private function insert(string $table,?string $key,array $data,string $objectType): array|WP_Error
    {
        global $wpdb;$key=$key===null?null:sanitize_text_field($key);if($key==='')$key=null;if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key is too long.');if($key!==null){$wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);if(!empty($wpdb->last_error))return $this->error('listing_idempotency_evidence_unavailable','Listing idempotency evidence could not be read.',503);if(is_array($existing)){if(!$this->replayCompatible($existing,$data))return $this->error('idempotency_payload_conflict','Idempotency key was already used with different immutable listing data.',409);return $existing+['idempotent_replay'=>true];}$data['idempotency_key']=$key;}if($wpdb->insert($table,$data)!==1){if($key!==null){$wpdb->last_error='';$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);if(!empty($wpdb->last_error))return $this->error('listing_idempotency_evidence_unavailable','Listing idempotency winner evidence could not be read.',503);if(is_array($existing)&&$this->replayCompatible($existing,$data))return $existing+['idempotent_replay'=>true];}return $this->error('database_error','Unable to persist listing record.',500);}$id=(int)$wpdb->insert_id;Logger::audit($objectType.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$objectType,(string)$id);$wpdb->last_error='';$created=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A);if(!empty($wpdb->last_error))return $this->error('listing_persistence_evidence_unavailable','Persisted listing evidence could not be read.',503);if(!is_array($created))return $this->error('listing_persistence_evidence_unavailable','Persisted listing evidence is unavailable after write.',503);return $created;
    }

    private function replayCompatible(array $existing,array $incoming): bool
    {
        $ignore=['id','idempotency_key','state','created_at','updated_at','created_by','approved_by','approved_at'];foreach($incoming as $field=>$value){if(in_array($field,$ignore,true))continue;if(!array_key_exists($field,$existing))continue;$left=$existing[$field];if((is_float($value)||is_int($value)||(is_numeric($value)&&is_numeric($left)))){if((string)(float)$left!==(string)(float)$value)return false;}elseif((string)$left!==(string)$value)return false;}return true;
    }

    private function exists(string $table,int $id): bool{global $wpdb;return $id>0&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d",$id))===1;}
    private function find(string $table,int $id): ?array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A);return is_array($r)?$r:null;}
    private function firstBy(string $table,string $field,int $id): ?array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE $field=%d ORDER BY id DESC LIMIT 1",$id),ARRAY_A);return is_array($r)?$r:null;}
    private function now(): string{return current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400): WP_Error{return new WP_Error('digiforge_'.$code,$message,['status'=>$status]);}
}
