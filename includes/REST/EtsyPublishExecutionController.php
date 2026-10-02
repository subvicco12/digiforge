<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\ConnectionTester;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Listings\EtsyControlledPublishExecutionCoordinator;
use DigiForge\Listings\EtsyOperationPreparationService;
use DigiForge\Listings\EtsyOperationRepository;
use DigiForge\Listings\EtsyPublishListingOperation;
use DigiForge\Listings\EtsyRequestFingerprint;
use DigiForge\Listings\EtsyTokenMetadataBridge;
use DigiForge\Listings\EtsyVerifiedShopIdentity;
use DigiForge\POD\ExecutionAuthorization;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Explicit, one-listing Etsy publication boundary. */
final class EtsyPublishExecutionController
{
    private const NS='digiforge/v1';
    public function register():void{add_action('rest_api_init',function():void{register_rest_route(self::NS,'/etsy/publish-listing',['methods'=>'POST','callback'=>[$this,'execute'],'permission_callback'=>[$this,'canExecute']]);});}
    public function canExecute():bool{return current_user_can('manage_digiforge_connections')&&current_user_can('manage_digiforge_listings');}

    public function execute(WP_REST_Request $request):WP_REST_Response|WP_Error
    {
        $body=$request->get_json_params();if(!is_array($body))return self::error('payload','JSON request body is required.',400);
        $key=trim((string)$request->get_header('Idempotency-Key'));$bodyKey=trim((string)($body['idempotency_key']??''));if($key===''&&$bodyKey!=='')$key=$bodyKey;
        if($key===''||strlen($key)>191||($bodyKey!==''&&!hash_equals($key,$bodyKey)))return self::error('idempotency','A single bounded idempotency key is required.',400);
        if(Settings::is_enabled('etsy_publish')!==true)return self::error('capability','Etsy Publish capability is not effectively enabled.',409);
        $integrationId=(int)($body['integration_id']??0);$shopId=(int)($body['shop_id']??0);$intentId=(int)($body['intent_id']??0);$packageId=(int)($body['draft_package_id']??0);
        if($integrationId<1||$shopId<1||$intentId<1||$packageId<1)return self::error('scope','Integration, shop, approved intent and package are required.',400);
        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if(!is_array($intent)||($intent['state']??'')!=='APPROVED_INTENT'||(int)($intent['draft_package_id']??0)!==$packageId)return self::error('intent','Approved Etsy intent/package scope is required.',409);
        if(!is_array($package)||(int)($package['approved_by']??0)<1||empty($package['approved_at']))return self::error('package','Human-approved draft package is required.',409);
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A);
        if(!is_array($listing)||($listing['state']??'')!=='APPROVED'||(int)($listing['approved_by']??0)<1)return self::error('listing','Explicitly approved listing is required.',409);
        $hash=strtolower(trim((string)($package['readiness_hash']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$hash))return self::error('evidence','Approved readiness hash is required.',409);
        $connection=(new ConnectionTester())->test($integrationId);if($connection instanceof WP_Error)return $connection;
        $identity=EtsyVerifiedShopIdentity::resolve($integrationId,(string)$listing['shop_reference'],$shopId);if($identity instanceof WP_Error)return $identity;
        $ops=new EtsyOperationRepository();$parent=$ops->confirmedCreateForScope($intentId,$packageId,(string)$shopId);if($parent instanceof WP_Error)return $parent;if(!is_array($parent))return self::error('parent','Confirmed CREATE_DRAFT evidence is required.',409);
        $etsyListingId=trim((string)($parent['external_reference']??''));if(!ctype_digit($etsyListingId)||(int)$etsyListingId<1)return self::error('identity','Authoritative Etsy listing identity is invalid.',409);
        $confirmedPublish=$ops->confirmedPublishForScope($intentId,$packageId,(string)$shopId,$etsyListingId);if($confirmedPublish instanceof WP_Error)return $confirmedPublish;if(is_array($confirmedPublish))return new WP_REST_Response(['state'=>'ETSY_PUBLISH_ALREADY_CONFIRMED','etsy_listing_id'=>(int)$etsyListingId,'external_execution_performed'=>false,'retry_permitted'=>false],200);
        $plan=EtsyPublishListingOperation::plan($shopId,(int)$etsyListingId);if($plan instanceof WP_Error)return $plan;
        $payload=(array)$plan['payload'];$fingerprint=EtsyRequestFingerprint::fromPayload($payload);if($fingerprint instanceof WP_Error)return $fingerprint;
        $existing=$ops->byKey((string)$shopId,$key);
        if($existing instanceof WP_Error)return $existing;
        if(is_array($existing)){
            $same=(int)($existing['intent_id']??0)===$intentId&&(int)($existing['draft_package_id']??0)===$packageId&&(string)($existing['operation_type']??'')==='PUBLISH_LISTING'&&hash_equals((string)($existing['resource_reference']??''),$etsyListingId)&&hash_equals((string)($existing['request_fingerprint']??''),$fingerprint)&&hash_equals((string)($existing['evidence_hash']??''),$hash);
            if(!$same)return self::error('idempotency','Idempotency key already belongs to a different Etsy publish request.',409);
            return new WP_REST_Response(['state'=>'ETSY_PUBLISH_ALREADY_ATTEMPTED','operation'=>$existing,'external_execution_performed'=>(string)($existing['state']??'')!=='NOT_SENT','retry_permitted'=>false],200);
        }
        $actor=get_current_user_id();$approval=['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$hash];
        $authorization=ExecutionAuthorization::issue($approval,'ETSY_PUBLISH_LISTING',$actor,str_replace('-','_',wp_generate_uuid4()),300,$fingerprint);if($authorization instanceof WP_Error)return $authorization;
        $operation=$ops->createFromPayload(['shop_reference'=>(string)$shopId,'intent_id'=>$intentId,'draft_package_id'=>$packageId,'operation_type'=>'PUBLISH_LISTING','resource_reference'=>$etsyListingId,'idempotency_key'=>$key,'authorization_hash'=>(string)$authorization['authorization_hash'],'evidence_hash'=>$hash],$payload);if($operation instanceof WP_Error)return $operation;
        if(($operation['idempotent_replay']??false)===true&&(string)($operation['state']??'')!=='NOT_SENT')return new WP_REST_Response(['state'=>'ETSY_PUBLISH_ALREADY_ATTEMPTED','operation'=>$operation,'external_execution_performed'=>true,'retry_permitted'=>false],200);
        $prepared=(new EtsyOperationPreparationService($ops))->prepare((int)$operation['id'],$authorization,$hash,$actor,time(),$payload);if($prepared instanceof WP_Error)return $prepared;
        $metadata=(new EtsyTokenMetadataBridge(new IntegrationRepository()))->evaluate($integrationId,time());if($metadata instanceof WP_Error)return $metadata;
        $result=(new EtsyControlledPublishExecutionCoordinator($ops))->execute($prepared,$operation,$metadata,$plan,['Content-Type'=>'application/x-www-form-urlencoded','Idempotency-Key'=>$key]);if($result instanceof WP_Error)return $result;
        return new WP_REST_Response($result,200);
    }
    private static function error(string $c,string $m,int $s):WP_Error{return new WP_Error('digiforge_etsy_publish_'.$c,$m,['status'=>$s]);}
}
