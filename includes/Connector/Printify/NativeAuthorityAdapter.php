<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\Security\ShopAccess;
use DigiForge\POD\{ScopedProviderConnection,PrintifyConnectionBoundary};
use DigiForge\Integrations\Repository;

/** Resolve current core authority; a caller-created ShopBinding is only a claim. */
final class NativeAuthorityAdapter
{
 public static function session(int $actor):array|\WP_Error {
  try{
   if($actor<1||get_current_user_id()!==$actor||!is_user_logged_in())return NativeFailure::error('session_required');
   $cookie=$_COOKIE[LOGGED_IN_COOKIE]??null;$token=wp_get_session_token();
   if(!is_string($cookie)||$token===''||wp_validate_auth_cookie($cookie,'logged_in')!==$actor)return NativeFailure::error('session_required');
   $manager=\WP_Session_Tokens::get_instance($actor);$session=$manager->get($token);
   if(!$manager->verify($token)||!is_array($session)||!is_int($session['expiration']??null)||$session['expiration']<=time())return NativeFailure::error('session_required');
   return ['fingerprint'=>hash('sha256',$token),'expires_at'=>$session['expiration']];
  }catch(\Throwable){return NativeFailure::error('session_unavailable');}
 }
 public static function module(string $shop,int $actor,array $caps):true|\WP_Error {
  if(!in_array($shop,['personalized_pod','standard_pod'],true))return NativeFailure::error('shop_scope');
  $session=self::session($actor);if(is_wp_error($session))return $session;
  try{foreach(array_unique(array_merge(['manage_digiforge_connections'],$caps))as $cap)if(!current_user_can($cap)||ShopAccess::authorize($shop,$cap,$actor)!==true)return NativeFailure::error('module_scope');return true;}
  catch(\Throwable){return NativeFailure::error('authority_unavailable');}
 }
 public static function scope(ShopBinding $scope,int $actor,array $caps=[]):array|\WP_Error {
  $access=self::module($scope->shopKey,$actor,$caps);if(is_wp_error($access))return $access;
  if($scope->expiresAt<=time()||$scope->credential->integrationId!==$scope->integrationId)return NativeFailure::error('stale_scope');
  try{
   $verified=(new ScopedProviderConnection())->verified($scope->shopKey,$scope->integrationId,'printify.connection.read');
   $connection=PrintifyConnectionBoundary::context($scope->shopKey,$scope->integrationId,'printify.connection.read');
   $integration=(new Repository())->find($scope->integrationId);
   if(is_wp_error($verified)||is_wp_error($connection)||!is_array($integration)||($integration['provider']??null)!=='printify'||($integration['status']??null)!=='CONFIGURED'||($integration['enabled']??null)!==true)return NativeFailure::error('reviewed_connection_required');
   if(($verified['review_revision']??null)!==$scope->revision||($verified['binding_hash']??null)!==$scope->bindingHash||($verified['expires_at']??null)!==$scope->expiresAt||($verified['external_shop_reference']??null)!==$scope->providerShopId||($verified['environment']??null)!==$scope->environment||($integration['environment']??null)!==$scope->environment||($integration['config']['shop_key']??null)!==$scope->shopKey||($integration['config']['shop_id']??null)!==$scope->providerShopId||($connection['binding_hash']??null)!==$scope->bindingHash)return NativeFailure::error('binding_revision_mismatch');
   // Owned upload membership requires the existing receipt resolver; caller membership is insufficient.
   if($scope->ownedUploads!==[])return NativeFailure::error('upload_receipt_join_required');
   $session=self::session($actor);return is_wp_error($session)?$session:['scope'=>$connection,'expires_at'=>min($scope->expiresAt,$session['expires_at'],time()+60),'session_fingerprint'=>$session['fingerprint']];
  }catch(\Throwable){return NativeFailure::error('connection_unavailable');}
 }
 public static function read(ExternalOperationIntent $intent):ProviderResult|\WP_Error {
  $caps=match($intent->operation){
   'PRODUCT_READ','PRODUCTS_READ','UPLOAD_READ','UPLOADS_READ'=>['manage_digiforge_products'],
   'ORDER_READ','ORDERS_READ'=>['manage_digiforge_orders'],
   default=>['manage_digiforge_pod','manage_digiforge_research'],
  };
  $access=self::module($intent->shopKey,$intent->actorId,$caps);if(is_wp_error($access))return $access;
  $attempted=false;
  try{
   $workflow=new \DigiForge\POD\PrintifyApiReadWorkflow();$grant=$workflow->resolveNative($intent);if(is_wp_error($grant))return $grant;
   $start=microtime(true);$attempted=null;$result=(new \DigiForge\POD\PrintifyControlledTransport())->executeApiRead($grant['plan_hash'],$grant['authorization_hash']);
   if(!is_wp_error($result)&&is_bool($result['record']['network_request_attempted']??null))$attempted=$result['record']['network_request_attempted'];
   if(is_wp_error($result)||($result['record']['status']??null)!=='SUCCEEDED'||!is_array($result['record']['data']??null))return new \WP_Error('printify_native_read_outcome_unknown','Native read outcome could not be verified.',['outcome'=>'UNKNOWN','network_request_attempted'=>is_wp_error($result)?null:($result['record']['network_request_attempted']??null),'retry_permitted'=>false,'external_execution_authorized'=>false]);
   $r=$workflow->receipt($result['hash']);$still=$workflow->resolveNative($intent);if(is_wp_error($r)||is_wp_error($still)||$still!==$grant||($r['status']??null)!=='SUCCEEDED')return new \WP_Error('printify_native_read_outcome_unknown','Current persisted native read evidence unavailable.',['outcome'=>'UNKNOWN','network_request_attempted'=>$result['record']['network_request_attempted']??null,'retry_permitted'=>false,'external_execution_authorized'=>false]);
   $data=$r['data'];
   if(!\DigiForge\POD\PrintifyNativeReadSources::extended($intent->operation)){
    $v=(new ScopedProviderConnection())->verified($intent->shopKey,$intent->integrationId,\DigiForge\POD\PrintifyApiReadWorkflow::CAPABILITY);
    if(is_wp_error($v)||$v['binding_hash']!==$grant['connection_binding_hash'])return self::unknown($attempted);
    $binding=new ShopBinding(['personalized_pod'=>2,'standard_pod'=>3][$intent->shopKey],$intent->shopKey,$intent->integrationId,$v['external_shop_reference'],$v['environment'],$v['review_revision'],new CredentialReference($intent->integrationId),$v['binding_hash'],$v['expires_at']);
    $body=match($intent->operation){'SHOPS_READ'=>$data['shops'],'PROVIDERS_READ'=>$data['providers'],'PROVIDER_READ'=>$data['providers'][0],default=>$data};
    $data=ReadProjection::project($intent,$binding,$body);
   }
   $final=$workflow->resolveNative($intent);if(is_wp_error($final)||$final!==$grant)return self::unknown($attempted);
   $receipt=['intent_id'=>$intent->intentId,'operation'=>$intent->operation,'shop_key'=>$intent->shopKey,'integration_id'=>$intent->integrationId,'outcome'=>'OBSERVED','status'=>200,'request_attempted'=>$r['network_request_attempted'],'request_sha256'=>$grant['request_fingerprint'],'binding_sha256'=>$grant['connection_binding_hash'],'observed_at'=>$r['observed_at'],'response_sha256'=>hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'duration_ms'=>(int)max(0,round((microtime(true)-$start)*1000))];
   $out=new ProviderResult($data,$receipt);return NativePortContract::valid('get_response',$out->jsonSerialize())?$out:self::unknown($attempted);
  }catch(\Throwable){return $attempted===false?NativeFailure::error('read_grant_unavailable'):self::unknown($attempted);}
 }
 private static function unknown(?bool $attempted):\WP_Error{return new \WP_Error('printify_native_read_outcome_unknown','Native read outcome could not be verified.',['outcome'=>'UNKNOWN','network_request_attempted'=>$attempted,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
