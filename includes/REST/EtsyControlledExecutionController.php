<?php
declare(strict_types=1);

namespace DigiForge\REST;

use DigiForge\Database\Tables;
use DigiForge\Integrations\ConnectionTester;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Listings\EtsyControlledDraftExecutionCoordinator;
use DigiForge\Listings\EtsyControlledTransportOrchestrator;
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
            register_rest_route(self::NS,'/etsy/controlled-draft',[
                'methods'=>'POST',
                'callback'=>[$this,'execute'],
                'permission_callback'=>[$this,'canExecute'],
            ]);
        });
    }

    public function canExecute(): bool
    {
        return current_user_can('manage_digiforge_connections');
    }

    public function execute(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $key=trim((string)$request->get_header('Idempotency-Key'));
        if($key===''||strlen($key)>191) return new WP_Error('digiforge_etsy_runtime_idempotency','A bounded Idempotency-Key header is required.',['status'=>400]);
        $body=$request->get_json_params();
        if(!is_array($body)) return new WP_Error('digiforge_etsy_runtime_payload','JSON request body is required.',['status'=>400]);
        $integrationId=(int)($body['integration_id']??0);
        $shopId=(int)($body['shop_id']??0);
        $intentId=(int)($body['intent_id']??0);
        $packageId=(int)($body['draft_package_id']??0);
        $payload=is_array($body['payload']??null)?$body['payload']:[];
        if($integrationId<1||$shopId<1||$intentId<1||$packageId<1||$payload===[]) return new WP_Error('digiforge_etsy_runtime_scope','Integration, shop, approved intent/package and draft payload are required.',['status'=>400]);

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

        $draft=EtsyDraftListingOperations::create($shopId,$payload);
        if($draft instanceof WP_Error) return $draft;
        $fingerprint=EtsyRequestFingerprint::fromPayload($payload);
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
        $result=$coordinator->execute($prepared,$operation,$metadata,$draft,['Content-Type'=>'application/json']);
        if($result instanceof WP_Error) return $result;
        return new WP_REST_Response($result,200);
    }
}
