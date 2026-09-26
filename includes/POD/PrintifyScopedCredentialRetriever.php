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
 public function consume(int $integrationId,callable $consumer):mixed{
  if(!Settings::is_enabled('printify'))return new WP_Error('digiforge_printify_credential_authorization','Scoped Printify authorization is required before credential access.',['status'=>409]);
  $integration=(new Repository())->find($integrationId);
  if(!$integration||($integration['provider']??'')!=='printify'||($integration['status']??'')!=='CONFIGURED'||($integration['enabled']??false)!==true)return new WP_Error('digiforge_printify_credential_scope','Enabled CONFIGURED Printify integration required.',['status'=>409]);
  global $wpdb;$cipher='';$name='';
  foreach(['personal_access_token','access_token'] as $candidate){$v=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$integrationId,$candidate));if(is_string($v)&&$v!==''){$cipher=$v;$name=$candidate;break;}}
  if($cipher==='')return new WP_Error('digiforge_printify_credential_missing','Authorized Printify credential is unavailable.',['status'=>409]);
  try{$token=CredentialVault::decrypt($cipher,Repository::secretContext($integrationId,$name));}catch(\Throwable){return new WP_Error('digiforge_printify_credential_decrypt','Authorized Printify credential could not be decrypted.',['status'=>500]);}
  try{return $consumer($token);}finally{$token='';$cipher='';}
 }
}
