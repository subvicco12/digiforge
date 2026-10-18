<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository;
use DigiForge\Core\Settings;
use WP_Error;
/** Single-use callback boundary for one configured Printify integration token. */
final class PrintifyScopedCredentialRetriever{
 public function consume(int $integrationId,callable $consumer,array $scope=[]):mixed{
  if(!Settings::is_enabled('printify'))return new WP_Error('digiforge_printify_credential_authorization','Scoped Printify authorization is required before credential access.',['status'=>409]);
  $integration=(new Repository())->find($integrationId);
  if(is_wp_error($integration))return $integration;
  if(!$integration||($integration['provider']??'')!=='printify'||($integration['status']??'')!=='CONFIGURED'||($integration['enabled']??false)!==true)return new WP_Error('digiforge_printify_credential_scope','Enabled CONFIGURED Printify integration required.',['status'=>409]);
  $capability=$scope['capability']??'';if(!is_string($capability))return PrintifyConnectionBoundary::error();
  $binding=PrintifyConnectionBoundary::runtime($integrationId,$capability,$scope);if(is_wp_error($binding))return $binding;
  global $wpdb;$cipher='';$name='';
  foreach(['personal_access_token','access_token'] as $candidate){$wpdb->last_error='';$v=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$integrationId,$candidate));if(!empty($wpdb->last_error))return new WP_Error('digiforge_printify_credential_evidence_unavailable','Authorized Printify credential evidence is unavailable.',['status'=>503]);if(is_string($v)&&$v!==''){$cipher=$v;$name=$candidate;break;}}
  if($cipher==='')return new WP_Error('digiforge_printify_credential_missing','Authorized Printify credential is unavailable.',['status'=>409]);
  try{$token=CredentialVault::decrypt($cipher,Repository::secretContext($integrationId,$name));}catch(\Throwable){return new WP_Error('digiforge_printify_credential_decrypt','Authorized Printify credential could not be decrypted.',['status'=>500]);}
  try{$material=PrintifyConnectionBoundary::material($integrationId,$token,$name);if(is_wp_error($material))return $material;$binding=PrintifyConnectionBoundary::runtime($integrationId,$capability,$scope);if(is_wp_error($binding))return $binding;$result=$consumer($token);
   $encoded=is_wp_error($result)?wp_json_encode([$result->get_error_codes(),$result->get_error_messages(),$result->get_error_data()]):wp_json_encode($result);if(!is_string($encoded)||str_contains($encoded,$token)||PrintifyCatalogReadContract::containsProtectedMaterial([json_decode($encoded,true)],$token))return new WP_Error('printify_credential_protected_result','Credential consumer result was discarded.',['status'=>502]);
   $binding=PrintifyConnectionBoundary::runtime($integrationId,$capability,$scope);if(is_wp_error($binding)&&is_wp_error($result)&&($result->get_error_data()['network_request_attempted']??false)===true)return $result;return is_wp_error($binding)?$binding:$result;
  }catch(\Throwable){return new WP_Error('digiforge_printify_credential_consumer','Authorized Printify credential consumer failed.',['status'=>502]);}finally{$token='';$cipher='';}
 }
}
