<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\Core\Settings;
use DigiForge\Integrations\Repository;
use DigiForge\Security\ShopAccess;
use DigiForge\POD\ScopedProviderConnection;
use DigiForge\POD\PrintifyConnectionBoundary;
use DigiForge\POD\PrintifyScopedCredentialRetriever;
use DigiForge\Security\Logger;

/** Compatibility adapter only: absent reviewed native authority is a hard denial. */
final class NativeHost implements HostInterface
{
 private array $authorized=[];
 private array $reserved=[];
 private const CAPS=[
  'SHOPS_READ'=>['manage_digiforge_pod'],
  'BLUEPRINTS_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'BLUEPRINT_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'PROVIDERS_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'PROVIDER_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'VARIANTS_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'SHIPPING_READ'=>['manage_digiforge_pod','manage_digiforge_research'],
  'PRODUCTS_READ'=>['manage_digiforge_products'],
  'PRODUCT_READ'=>['manage_digiforge_products'],
  'ORDERS_READ'=>['manage_digiforge_orders'],
  'ORDER_READ'=>['manage_digiforge_orders'],
  'UPLOADS_READ'=>['manage_digiforge_products'],
  'UPLOAD_READ'=>['manage_digiforge_products'],
 ];
 public function authorize(ExternalOperationIntent $intent):ShopBinding|\WP_Error {
  try {
   if(!class_exists(Settings::class)||!Settings::is_enabled('printify')||Settings::safety_locked())return self::error();
   if(!function_exists('get_current_user_id')||get_current_user_id()!==$intent->actorId||$intent->actorId<1)return self::error();
   $cap=self::CAPS[$intent->operation]??null;
   if($cap===null||$intent->shopKey!=='personalized_pod'||$intent->integrationId!==3||!function_exists('current_user_can')||!current_user_can('manage_digiforge_connections'))return self::error();
   if(!class_exists(ShopAccess::class)||!class_exists(ScopedProviderConnection::class)||!class_exists(PrintifyConnectionBoundary::class)||!class_exists(PrintifyScopedCredentialRetriever::class))return self::error();
   if(ShopAccess::authorize($intent->shopKey,'manage_digiforge_connections',$intent->actorId)!==true)return self::error();
   foreach($cap as $required)if(!current_user_can($required)||ShopAccess::authorize($intent->shopKey,$required,$intent->actorId)!==true)return self::error();
   $integration=(new Repository())->find($intent->integrationId);
   if(is_wp_error($integration))return $integration;
   if(!is_array($integration)||($integration['provider']??null)!=='printify'||($integration['status']??null)!=='CONFIGURED'||($integration['enabled']??null)!==true||($integration['environment']??null)!=='production')return self::error();
   $config=$integration['config']??[];
   if(($config['shop_id']??null)!=='28930593'||($config['shop_key']??null)!==$intent->shopKey||!is_string($config['shop_id']??null)||!preg_match('/^[1-9][0-9]{0,18}$/D',$config['shop_id']))return self::error();
   $verified=(new ScopedProviderConnection())->verified($intent->shopKey,$intent->integrationId,'printify.connection.read');
   if(is_wp_error($verified))return $verified;
   if(($verified['external_shop_reference']??null)!==$config['shop_id']||($verified['environment']??null)!=='production'||!is_int($verified['expires_at']??null)||$verified['expires_at']<=time()||!is_int($verified['review_revision']??null)||!is_string($verified['binding_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$verified['binding_hash']))return self::error();
   $assets=[];
   if(in_array($intent->operation,['UPLOAD_READ','UPLOADS_READ'],true)){
    if(!$intent->sourceReferences||!class_exists(\DigiForge\POD\PrintifyUploadOwnership::class))return self::error();
    $scope=PrintifyConnectionBoundary::context($intent->shopKey,$intent->integrationId,'printify.connection.read');if(is_wp_error($scope))return $scope;
    $owned=\DigiForge\POD\PrintifyUploadOwnership::resolve($intent->shopKey,$intent->integrationId,$scope,$intent->sourceReferences);if(is_wp_error($owned))return $owned;
    foreach($owned as $item){if(!is_string($item['image_id']??null))return self::error();$assets[]=$item['image_id'];}
    if($intent->operation==='UPLOAD_READ'&&!in_array($intent->input['upload_id']??null,$assets,true))return self::error();
   }
   $binding=new ShopBinding(2,$intent->shopKey,$intent->integrationId,$config['shop_id'],'production',$verified['review_revision'],new CredentialReference($intent->integrationId),$verified['binding_hash'],$verified['expires_at'],$assets);
   $this->authorized[$intent->intentId]=[$intent,$binding];
   return $binding;
  }catch(\Throwable){return self::error();}
 }
 public function reserve(ExternalOperationIntent $intent,ShopBinding $binding):true|\WP_Error {
  $prior=$this->authorized[$intent->intentId]??null;
  if(!is_array($prior)||$prior[0]!==$intent||$prior[1]->fingerprint()!==$binding->fingerprint())return self::error();
  $current=$this->authorize($intent);
  if(is_wp_error($current))return $current;
  if($current->fingerprint()!==$binding->fingerprint())return self::error();
  $result=(new ReadBudget())->reserve($intent);
  if($result===true)$this->reserved[$binding->credential->integrationId]=[$intent,$binding];
  return $result;
 }
 public function consume(CredentialReference $reference,callable $consumer):mixed {
  $grant=$this->reserved[$reference->integrationId]??null;
  unset($this->reserved[$reference->integrationId]);
  if(!is_array($grant)||$grant[1]->credential!==$reference)return self::error();
  [$intent,$binding]=$grant;$current=$this->authorize($intent);
  if(is_wp_error($current))return $current;
  if($current->fingerprint()!==$binding->fingerprint())return self::error();
  try {
   $scope=PrintifyConnectionBoundary::context($intent->shopKey,$reference->integrationId,'printify.connection.read');
   if(is_wp_error($scope))return $scope;
   if(($scope['binding_hash']??null)!==$binding->bindingHash)return self::error();
   return (new PrintifyScopedCredentialRetriever())->consume($reference->integrationId,$consumer,$scope);
  }catch(\Throwable){return self::error();}
 }
 public function record(array $receipt):bool {
  // Fixed scalar allowlist excludes bodies, URLs, credentials and provider payloads.
  $public=[];
  foreach(['intent_id','operation','shop_key','integration_id','outcome','status','request_attempted','duration_ms','response_sha256','request_sha256','binding_sha256','observed_at'] as $key){
   if(!array_key_exists($key,$receipt))continue;
   $value=$receipt[$key];
   if(in_array($key,['response_sha256','request_sha256','binding_sha256'],true)){
    if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))return false;
    $public[$key]=$value;continue;
   }
   if($key==='observed_at'){
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value))return false;
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$value)return false;
    $public[$key]=$value;continue;
   }
   if(is_bool($value)||is_int($value))$public[$key]=$value;
   elseif(is_string($value)&&preg_match('/^[A-Za-z0-9_.:-]{1,96}$/D',$value))$public[$key]=$value;
   else return false;
  }
  try{return class_exists(Logger::class)&&Logger::write('printify_connector_read',$public,'printify_connector');}catch(\Throwable){return false;}
 }
 private static function error():\WP_Error {return new \WP_Error('printify_native_authority_required','Current scoped native Printify authority is required.',['status'=>403]);}
}
