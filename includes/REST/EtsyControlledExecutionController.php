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
use DigiForge\Listings\EtsyDraftListingOperations;
use DigiForge\Listings\EtsyDraftOperationPipeline;
use DigiForge\Listings\EtsyOperationPreparationService;
use DigiForge\Listings\EtsyOperationRepository;
use DigiForge\Listings\EtsyRequestFingerprint;
use DigiForge\Listings\EtsyTokenMetadataBridge;
use DigiForge\Listings\EtsyVerifiedShopIdentity;
use DigiForge\POD\ExecutionAuthorization;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class EtsyControlledExecutionController
{
    private const NS='digiforge/v1';

    public function register(): void
    {
        add_action('rest_api_init',function():void{
            register_rest_route(self::NS,'/etsy/controlled-digital-file',['methods'=>'POST','callback'=>[$this,'uploadDigitalFile'],'permission_callback'=>[$this,'canExecute']]);
            register_rest_route(self::NS,'/etsy/controlled-draft',[
                'methods'=>'POST',
                'callback'=>[$this,'execute'],
                'permission_callback'=>[$this,'canExecute'],
            ]);
        });
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
        $operations=new EtsyOperationRepository(); $parent=$operations->confirmedCreateForScope($intentId,$packageId,(string)$shopId); if(!is_array($parent))return new WP_Error('digiforge_etsy_file_parent','A confirmed CREATE_DRAFT is required before digital upload.',['status'=>409]);
        $listingId=(int)($parent['external_reference']??0); if($listingId<1)return new WP_Error('digiforge_etsy_file_parent_identity','Confirmed CREATE_DRAFT listing identity is invalid.',['status'=>409]);
        $selected=EtsyCustomerDownloadResolver::resolve((int)$listing['product_version_id']); if($selected instanceof WP_Error)return $selected;
        $multipart=EtsyMultipartDigitalFileRequest::build($shopId,$listingId,(string)$selected['absolute_path'],1,(array)$selected['release_bundle'],(array)$selected['manifest_entry']); if($multipart instanceof WP_Error)return $multipart;
        $draft=EtsyDraftListingOperations::uploadFile($shopId,$listingId,$multipart); if($draft instanceof WP_Error)return $draft;
        $payload=(array)$draft['payload']; $fingerprint=EtsyRequestFingerprint::fromPayload($payload); if($fingerprint instanceof WP_Error)return $fingerprint; $evidenceHash=strtolower(trim((string)$package['readiness_hash'])); $actor=get_current_user_id();
        $approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$evidenceHash]; $authorization=ExecutionAuthorization::issue($approval,'ETSY_DRAFT_FILE',$actor,str_replace('-','_',wp_generate_uuid4()),300,$fingerprint); if($authorization instanceof WP_Error)return $authorization;
        $operation=$operations->createFromPayload(['shop_reference'=>(string)$shopId,'intent_id'=>$intentId,'draft_package_id'=>$packageId,'operation_type'=>'UPLOAD_FILE','resource_reference'=>(string)$listingId,'idempotency_key'=>$key,'authorization_hash'=>(string)$authorization['authorization_hash'],'evidence_hash'=>$evidenceHash],$payload); if($operation instanceof WP_Error)return $operation;
        $prepared=(new EtsyOperationPreparationService($operations))->prepare((int)$operation['id'],$authorization,$evidenceHash,$actor,time(),$externalPayload); if($prepared instanceof WP_Error)return $prepared; $metadata=(new EtsyTokenMetadataBridge(new IntegrationRepository()))->evaluate($integrationId,time()); if($metadata instanceof WP_Error)return $metadata;
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
        ],$payload);
        if($operation instanceof WP_Error) return $operation;
        if(($operation['idempotent_replay']??false)===true && (string)($operation['state']??'')!=='NOT_SENT') {
            return new WP_REST_Response(['state'=>'ETSY_CONTROLLED_DRAFT_ALREADY_ATTEMPTED','operation'=>$operation,'external_execution_performed'=>true,'publish_permitted'=>false],200);
        }

        $prepared=(new EtsyOperationPreparationService($operations))->prepare((int)$operation['id'],$authorization,$evidenceHash,$actor,time(),$payload);
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
