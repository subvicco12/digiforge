<?php
declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Database\Tables;
use DigiForge\Integrations\ConnectionTester;
use DigiForge\Integrations\EtsySellerTaxonomyClient;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Listings\EtsyControlledDraftExecutionCoordinator;
use DigiForge\Listings\EtsyApprovedPackageCompiler;
use DigiForge\Listings\EtsyControlledTransportOrchestrator;
use DigiForge\Listings\EtsyCustomerDownloadResolver;
use DigiForge\Listings\EtsyMultipartDigitalFileRequest;
use DigiForge\Listings\EtsyMultipartImageRequest;
use DigiForge\Listings\EtsyDraftListingOperations;
use DigiForge\Listings\EtsyDraftOperationPipeline;
use DigiForge\Listings\EtsyOperationPreparationService;
use DigiForge\Listings\EtsyOperationRepository;
use DigiForge\Listings\EtsyRequestFingerprint;
use DigiForge\Listings\EtsyTokenMetadataBridge;
use DigiForge\Listings\EtsyVerifiedShopIdentity;
use DigiForge\POD\ExecutionAuthorization;
use DigiForge\ProductFactory\AssetStorage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class EtsyControlledExecutionController
{
    private const NS='digiforge/v1';

    public function register(): void
    {
        add_action('rest_api_init',function():void{
            register_rest_route(self::NS,'/etsy/controlled-listing-image',['methods'=>'POST','callback'=>[$this,'uploadListingImage'],'permission_callback'=>[$this,'canExecute']]);
            register_rest_route(self::NS,'/etsy/controlled-digital-file',['methods'=>'POST','callback'=>[$this,'uploadDigitalFile'],'permission_callback'=>[$this,'canExecute']]);
            register_rest_route(self::NS,'/etsy/controlled-draft',[
                'methods'=>'POST',
                'callback'=>[$this,'execute'],
                'permission_callback'=>[$this,'canExecute'],
            ]);
        });
    }


    public function uploadListingImage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$request->get_json_params(); if(!is_array($body))return new WP_Error('digiforge_etsy_image_payload','JSON request body is required.',['status'=>400]);
        $key=trim((string)$request->get_header('Idempotency-Key')); $bodyKey=trim((string)($body['idempotency_key']??'')); if($key===''&&$bodyKey!=='')$key=$bodyKey;
        if($key===''||strlen($key)>191||($bodyKey!==''&&!hash_equals($key,$bodyKey)))return new WP_Error('digiforge_etsy_image_idempotency','A single bounded idempotency key is required.',['status'=>400]);
        $integrationId=(int)($body['integration_id']??0); $shopId=(int)($body['shop_id']??0); $intentId=(int)($body['intent_id']??0); $packageId=(int)($body['draft_package_id']??0); $assetRevisionId=(int)($body['asset_revision_id']??0); $rank=(int)($body['rank']??1);
        if($integrationId<1||$shopId<1||$intentId<1||$packageId<1||$assetRevisionId<1||$rank<1||$rank>10)return new WP_Error('digiforge_etsy_image_scope','Integration, shop, approved intent/package, image revision and bounded rank are required.',['status'=>400]);
        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A); $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if(!is_array($intent)||($intent['state']??'')!=='APPROVED_INTENT'||(int)($intent['draft_package_id']??0)!==$packageId||!is_array($package)||(int)($package['approved_by']??0)<1)return new WP_Error('digiforge_etsy_image_approval','Approved intent/package scope is required.',['status'=>409]);
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A); if(!is_array($listing)||($listing['state']??'')!=='APPROVED')return new WP_Error('digiforge_etsy_image_listing','Approved listing is required.',['status'=>409]);
        $releaseBundleId=(int)$wpdb->get_var($wpdb->prepare('SELECT release_bundle_id FROM '.Tables::listing_media().' WHERE listing_id=%d AND state=%s ORDER BY position_index ASC,id ASC LIMIT 1',(int)$listing['id'],'BOUND'));
        $bundle=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Tables::release_bundles()." WHERE id=%d AND state='RELEASE_READY' AND approved_by>0 AND approved_at IS NOT NULL LIMIT 1",$releaseBundleId),ARRAY_A);
        $manifest=is_array($bundle)?json_decode((string)($bundle['manifest']??''),true):null;
        if(!is_array($manifest))return new WP_Error('digiforge_etsy_image_bundle','Approved bound release bundle is required.',['status'=>409]);
        $asset=$wpdb->get_row($wpdb->prepare('SELECT ar.*,s.product_version_id,s.asset_type,s.format FROM '.Tables::asset_revisions().' ar JOIN '.Tables::asset_specs().' s ON s.id=ar.asset_spec_id WHERE ar.id=%d LIMIT 1',$assetRevisionId),ARRAY_A);
        if(!is_array($asset)||(int)($asset['product_version_id']??0)!==(int)$listing['product_version_id']||($asset['state']??'')!=='APPROVED'||($asset['asset_type']??'')!=='listing_image'||!in_array(strtolower((string)($asset['format']??'')),['png','jpg','jpeg','webp'],true))return new WP_Error('digiforge_etsy_image_asset','Image revision is not an approved listing-image asset for this product.',['status'=>409]);
        $manifestEntry=null; foreach($manifest as $entry){if(is_array($entry)&&(int)($entry['asset_revision_id']??0)===$assetRevisionId){$manifestEntry=$entry;break;}}
        if(is_array($manifestEntry)){
            if(!hash_equals(strtolower((string)($manifestEntry['checksum_sha256']??'')),strtolower((string)($asset['checksum_sha256']??''))))return new WP_Error('digiforge_etsy_image_asset','Bound image checksum does not match the approved revision.',['status'=>409]);
        }else{
            $provenance=json_decode((string)($asset['provenance']??''),true);
            $sourceRevisionId=is_array($provenance)?(int)($provenance['source_revision_id']??0):0;
            $sourceSpecId=is_array($provenance)?(int)($provenance['source_asset_spec_id']??0):0;
            $sourceChecksum=is_array($provenance)?strtolower(trim((string)($provenance['source_checksum_sha256']??''))):'';
            if(!is_array($provenance)||($provenance['derivation_type']??'')!=='local_svg_to_png'||($provenance['approval_inherited']??true)!==false||($provenance['external_action_performed']??true)!==false||$sourceRevisionId<1||$sourceSpecId<1||!preg_match('/^[a-f0-9]{64}$/',$sourceChecksum))return new WP_Error('digiforge_etsy_image_lineage','Derived listing image lacks bounded local source provenance.',['status'=>409]);
            $sourceManifestEntry=null; foreach($manifest as $entry){if(is_array($entry)&&(int)($entry['asset_revision_id']??0)===$sourceRevisionId&&(int)($entry['asset_spec_id']??0)===$sourceSpecId){$sourceManifestEntry=$entry;break;}}
            if(!is_array($sourceManifestEntry)||!hash_equals(strtolower((string)($sourceManifestEntry['checksum_sha256']??'')),$sourceChecksum))return new WP_Error('digiforge_etsy_image_lineage','Derived listing image source is not checksum-bound to the approved release bundle.',['status'=>409]);
            $source=$wpdb->get_row($wpdb->prepare('SELECT ar.*,s.product_version_id FROM '.Tables::asset_revisions().' ar JOIN '.Tables::asset_specs().' s ON s.id=ar.asset_spec_id WHERE ar.id=%d AND ar.asset_spec_id=%d LIMIT 1',$sourceRevisionId,$sourceSpecId),ARRAY_A);
            if(!is_array($source)||(int)($source['product_version_id']??0)!==(int)$listing['product_version_id']||($source['state']??'')!=='APPROVED'||!hash_equals(strtolower((string)($source['checksum_sha256']??'')),$sourceChecksum)||!hash_equals((string)($source['storage_reference']??''),(string)($provenance['source_storage_reference']??'')))return new WP_Error('digiforge_etsy_image_lineage','Derived listing image source no longer matches approved immutable source evidence.',['status'=>409]);
        }
        $connection=(new ConnectionTester())->test($integrationId); if($connection instanceof WP_Error)return $connection; $identity=EtsyVerifiedShopIdentity::resolve($integrationId,(string)$listing['shop_reference'],$shopId); if($identity instanceof WP_Error)return $identity;
        $operations=new EtsyOperationRepository(); $parent=$operations->confirmedCreateForScope($intentId,$packageId,(string)$shopId); if($parent instanceof WP_Error)return $parent; if(!is_array($parent))return new WP_Error('digiforge_etsy_image_parent','A confirmed CREATE_DRAFT is required before image upload.',['status'=>409]);
        $listingId=(int)($parent['external_reference']??0); if($listingId<1)return new WP_Error('digiforge_etsy_image_parent_identity','Confirmed CREATE_DRAFT listing identity is invalid.',['status'=>409]);
        $path=AssetStorage::absolutePath((string)$asset['storage_reference']); if($path===null)return new WP_Error('digiforge_etsy_image_storage','Approved image bytes are unavailable in protected asset storage.',['status'=>409]); $multipart=EtsyMultipartImageRequest::build($shopId,$listingId,$path,$rank); if($multipart instanceof WP_Error)return $multipart;
        if(!hash_equals(strtolower((string)$asset['checksum_sha256']),strtolower((string)$multipart['sha256'])))return new WP_Error('digiforge_etsy_image_integrity','Resolved image bytes do not match the approved asset revision.',['status'=>409]);
        $draft=EtsyDraftListingOperations::uploadImage($shopId,$listingId,$multipart); if($draft instanceof WP_Error)return $draft; $payload=(array)$draft['payload']; $fingerprint=EtsyRequestFingerprint::fromPayload($payload); if($fingerprint instanceof WP_Error)return $fingerprint;
        $evidenceHash=strtolower(trim((string)$package['readiness_hash'])); $actor=get_current_user_id();
        $existing=$operations->byKey((string)$shopId,$key); if($existing instanceof WP_Error)return $existing; if(is_array($existing)){if((string)($existing['operation_type']??'')!=='ATTACH_IMAGE'||(int)($existing['intent_id']??0)!==$intentId||(int)($existing['draft_package_id']??0)!==$packageId||!hash_equals((string)($existing['resource_reference']??''),(string)$listingId)||!hash_equals((string)($existing['request_fingerprint']??''),(string)$fingerprint)||!hash_equals((string)($existing['evidence_hash']??''),$evidenceHash))return new WP_Error('digiforge_etsy_image_idempotency_conflict','Idempotency key already belongs to a different Etsy image request.',['status'=>409]); return new WP_REST_Response(['state'=>'ETSY_CONTROLLED_IMAGE_ALREADY_ATTEMPTED','operation'=>$existing,'external_execution_performed'=>(string)($existing['state']??'')!=='NOT_SENT','publish_permitted'=>false,'automatic_retry_permitted'=>false],200);}
        $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$evidenceHash];
        $authorization=ExecutionAuthorization::issue($approval,'ETSY_DRAFT_IMAGE',$actor,str_replace('-','_',wp_generate_uuid4()),300,$fingerprint); if($authorization instanceof WP_Error)return $authorization;
        $operation=$operations->createFromPayload(['shop_reference'=>(string)$shopId,'intent_id'=>$intentId,'draft_package_id'=>$packageId,'operation_type'=>'ATTACH_IMAGE','resource_reference'=>(string)$listingId,'idempotency_key'=>$key,'authorization_hash'=>(string)$authorization['authorization_hash'],'evidence_hash'=>$evidenceHash],$payload); if($operation instanceof WP_Error)return $operation;
        if(($operation['idempotent_replay']??false)===true&&(string)($operation['state']??'')!=='NOT_SENT')return new WP_REST_Response(['state'=>'ETSY_CONTROLLED_IMAGE_ALREADY_ATTEMPTED','operation'=>$operation,'external_execution_performed'=>true,'publish_permitted'=>false],200);
        $prepared=(new EtsyOperationPreparationService($operations))->prepare((int)$operation['id'],$authorization,$evidenceHash,$actor,time(),$payload); if($prepared instanceof WP_Error)return $prepared; $metadata=(new EtsyTokenMetadataBridge(new IntegrationRepository()))->evaluate($integrationId,time()); if($metadata instanceof WP_Error)return $metadata;
        $result=(new EtsyControlledDraftExecutionCoordinator(new EtsyDraftOperationPipeline(new EtsyControlledTransportOrchestrator()),$operations))->execute($prepared,$operation,$metadata,$draft,['Content-Type'=>'multipart/form-data','Idempotency-Key'=>$key],$multipart); if($result instanceof WP_Error)return $result;
        return new WP_REST_Response($result+['publish_permitted'=>false],200);
    }

    public function uploadDigitalFile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$request->get_json_params(); if(!is_array($body))return new WP_Error('digiforge_etsy_file_payload','JSON request body is required.',['status'=>400]);
        $key=trim((string)$request->get_header('Idempotency-Key')); $bodyKey=trim((string)($body['idempotency_key']??'')); if($key===''&&$bodyKey!=='')$key=$bodyKey;
        if($key===''||strlen($key)>191||($bodyKey!==''&&!hash_equals($key,$bodyKey)))return new WP_Error('digiforge_etsy_file_idempotency','A single bounded idempotency key is required.',['status'=>400]);
        $integrationId=(int)($body['integration_id']??0); $shopId=(int)($body['shop_id']??0); $intentId=(int)($body['intent_id']??0); $packageId=(int)($body['draft_package_id']??0);
        if($integrationId<1||$shopId<1||$intentId<1||$packageId<1)return new WP_Error('digiforge_etsy_file_scope','Integration, shop, approved intent and package are required.',['status'=>400]);
        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A); $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if(!is_array($intent)||($intent['state']??'')!=='APPROVED_INTENT'||(int)($intent['draft_package_id']??0)!==$packageId||!is_array($package)||(int)($package['approved_by']??0)<1)return new WP_Error('digiforge_etsy_file_approval','Approved intent/package scope is required.',['status'=>409]);
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A); if(!is_array($listing)||($listing['state']??'')!=='APPROVED')return new WP_Error('digiforge_etsy_file_listing','Approved listing is required.',['status'=>409]);
        $connection=(new ConnectionTester())->test($integrationId); if($connection instanceof WP_Error)return $connection; $identity=EtsyVerifiedShopIdentity::resolve($integrationId,(string)$listing['shop_reference'],$shopId); if($identity instanceof WP_Error)return $identity;
        $operations=new EtsyOperationRepository(); $parent=$operations->confirmedCreateForScope($intentId,$packageId,(string)$shopId); if($parent instanceof WP_Error)return $parent; if(!is_array($parent))return new WP_Error('digiforge_etsy_file_parent','A confirmed CREATE_DRAFT is required before digital upload.',['status'=>409]);
        $listingId=(int)($parent['external_reference']??0); if($listingId<1)return new WP_Error('digiforge_etsy_file_parent_identity','Confirmed CREATE_DRAFT listing identity is invalid.',['status'=>409]);
        $selected=EtsyCustomerDownloadResolver::resolve((int)$listing['product_version_id']); if($selected instanceof WP_Error)return $selected;
        $multipart=EtsyMultipartDigitalFileRequest::build($shopId,$listingId,(string)$selected['absolute_path'],1,(array)$selected['release_bundle'],(array)$selected['manifest_entry']); if($multipart instanceof WP_Error)return $multipart;
        $draft=EtsyDraftListingOperations::uploadFile($shopId,$listingId,$multipart); if($draft instanceof WP_Error)return $draft;
        $payload=(array)$draft['payload']; $fingerprint=EtsyRequestFingerprint::fromPayload($payload); if($fingerprint instanceof WP_Error)return $fingerprint; $evidenceHash=strtolower(trim((string)$package['readiness_hash'])); $actor=get_current_user_id();
        $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$evidenceHash]; $authorization=ExecutionAuthorization::issue($approval,'ETSY_DRAFT_FILE',$actor,str_replace('-','_',wp_generate_uuid4()),300,$fingerprint); if($authorization instanceof WP_Error)return $authorization;
        $operation=$operations->createFromPayload(['shop_reference'=>(string)$shopId,'intent_id'=>$intentId,'draft_package_id'=>$packageId,'operation_type'=>'UPLOAD_FILE','resource_reference'=>(string)$listingId,'idempotency_key'=>$key,'authorization_hash'=>(string)$authorization['authorization_hash'],'evidence_hash'=>$evidenceHash],$payload); if($operation instanceof WP_Error)return $operation;
        $prepared=(new EtsyOperationPreparationService($operations))->prepare((int)$operation['id'],$authorization,$evidenceHash,$actor,time(),$payload); if($prepared instanceof WP_Error)return $prepared; $metadata=(new EtsyTokenMetadataBridge(new IntegrationRepository()))->evaluate($integrationId,time()); if($metadata instanceof WP_Error)return $metadata;
        $result=(new EtsyControlledDraftExecutionCoordinator(new EtsyDraftOperationPipeline(new EtsyControlledTransportOrchestrator()),$operations))->execute($prepared,$operation,$metadata,$draft,['Content-Type'=>'multipart/form-data','Idempotency-Key'=>$key],$multipart); if($result instanceof WP_Error)return $result;
        return new WP_REST_Response($result+['publish_permitted'=>false],200);
    }

    public function canExecute(): bool
    {
        return current_user_can('manage_digiforge_connections');
    }

    public function execute(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$request->get_json_params();
        $key=trim((string)$request->get_header('Idempotency-Key'));
        $bodyKey=is_array($body)?trim((string)($body['idempotency_key']??'')):'';
        if($key==='' && $bodyKey!=='') $key=$bodyKey;
        if($key===''||strlen($key)>191) return new WP_Error('digiforge_etsy_runtime_idempotency','A bounded Idempotency-Key header or JSON idempotency_key is required.',['status'=>400]);
        if($bodyKey!=='' && !hash_equals($key,$bodyKey)) return new WP_Error('digiforge_etsy_runtime_idempotency_mismatch','Header and body idempotency keys must match when both are supplied.',['status'=>409]);
        if(!is_array($body)) return new WP_Error('digiforge_etsy_runtime_payload','JSON request body is required.',['status'=>400]);
        $integrationId=(int)($body['integration_id']??0);
        $shopId=(int)($body['shop_id']??0);
        $intentId=(int)($body['intent_id']??0);
        $packageId=(int)($body['draft_package_id']??0);
        $classification=is_array($body['etsy_classification']??null)?$body['etsy_classification']:[];
        if($integrationId<1||$shopId<1||$intentId<1||$packageId<1||$classification===[]) return new WP_Error('digiforge_etsy_runtime_scope','Integration, shop, approved intent/package and verified Etsy classification are required.',['status'=>400]);

        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if(!is_array($intent)||($intent['state']??'')!=='APPROVED_INTENT'||(int)($intent['draft_package_id']??0)!==$packageId) return new WP_Error('digiforge_etsy_runtime_intent','Approved Etsy intent/package scope is required.',['status'=>409]);
        if(!is_array($package)||(int)($package['approved_by']??0)<1||empty($package['approved_at'])) return new WP_Error('digiforge_etsy_runtime_package','Human-approved draft package is required.',['status'=>409]);
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A);
        if(!is_array($listing)||($listing['state']??'')!=='APPROVED') return new WP_Error('digiforge_etsy_runtime_listing','Approved listing is required.',['status'=>409]);

        $connection=(new ConnectionTester())->test($integrationId);
        if($connection instanceof WP_Error) return $connection;
        $identity=EtsyVerifiedShopIdentity::resolve($integrationId,(string)$listing['shop_reference'],$shopId);
        if($identity instanceof WP_Error) return $identity;
        $taxonomyId=(int)($classification['taxonomy_id']??0);
        if($taxonomyId<1) return new WP_Error('digiforge_etsy_runtime_taxonomy','A positive Etsy taxonomy_id is required for verified taxonomy lookup.',['status'=>400]);
        $taxonomyEvidence=(new EtsySellerTaxonomyClient())->fetchVerified($integrationId,$taxonomyId);
        if($taxonomyEvidence instanceof WP_Error) return $taxonomyEvidence;
        $classification['taxonomy_evidence']=$taxonomyEvidence;

        $payload=EtsyApprovedPackageCompiler::compile($package,$listing,$classification);
        if($payload instanceof WP_Error) return $payload;
        $draft=EtsyDraftListingOperations::create($shopId,$payload);
        if($draft instanceof WP_Error) return $draft;
        // Bind authorization, ledger fingerprint and preparation to the exact
        // sanitized payload that is permitted to cross the Etsy boundary.
        // Compiler-only _digiforge evidence must never affect or enter the
        // external request fingerprint.
        $externalPayload=is_array($draft['payload']??null)?$draft['payload']:[];
        if($externalPayload===[]) return new WP_Error('digiforge_etsy_runtime_external_payload','Sanitized external Etsy draft payload is required.',['status'=>409]);
        $fingerprint=EtsyRequestFingerprint::fromPayload($externalPayload);
        if($fingerprint instanceof WP_Error) return $fingerprint;
        $evidenceHash=strtolower(trim((string)($package['readiness_hash']??'')));
        $actor=get_current_user_id();
        $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$evidenceHash];
        $authorization=ExecutionAuthorization::issue($approval,'ETSY_DRAFT_CREATE',$actor,str_replace('-','_',wp_generate_uuid4()),300,$fingerprint);
        if($authorization instanceof WP_Error) return $authorization;

        $operations=new EtsyOperationRepository();
        $operation=$operations->createFromPayload([
            'shop_reference'=>(string)$shopId,
            'intent_id'=>$intentId,
            'draft_package_id'=>$packageId,
            'operation_type'=>'CREATE_DRAFT',
            'idempotency_key'=>$key,
            'authorization_hash'=>(string)$authorization['authorization_hash'],
            'evidence_hash'=>$evidenceHash,
        ],$externalPayload);
        if($operation instanceof WP_Error) return $operation;
        if(($operation['idempotent_replay']??false)===true && (string)($operation['state']??'')!=='NOT_SENT') {
            return new WP_REST_Response(['state'=>'ETSY_CONTROLLED_DRAFT_ALREADY_ATTEMPTED','operation'=>$operation,'external_execution_performed'=>true,'publish_permitted'=>false],200);
        }

        $prepared=(new EtsyOperationPreparationService($operations))->prepare((int)$operation['id'],$authorization,$evidenceHash,$actor,time(),$externalPayload);
        if($prepared instanceof WP_Error) return $prepared;
        $metadata=(new EtsyTokenMetadataBridge(new IntegrationRepository()))->evaluate($integrationId,time());
        if($metadata instanceof WP_Error) return $metadata;
        $coordinator=new EtsyControlledDraftExecutionCoordinator(
            new EtsyDraftOperationPipeline(new EtsyControlledTransportOrchestrator()),
            $operations
        );
        $result=$coordinator->execute($prepared,$operation,$metadata,$draft,['Content-Type'=>(string)($draft['content_type']??'application/x-www-form-urlencoded'),'Idempotency-Key'=>$key]);
        if($result instanceof WP_Error) return $result;
        return new WP_REST_Response($result,200);
    }
}
