<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use DigiForge\Integrations\{CredentialVault,Repository as Integrations};
use DigiForge\Orders\Validator;
use DigiForge\Security\Logger;
use WP_Error;

/** Fixed-endpoint real HTTP sender with a durable one-use claim. Default network authority is OFF. */
final class ImageGenerationHttpClient
{
 public function __construct(private ?ImageGenerationCredentialCustody $custody=null){}
 public static function lockName(string $id):string {global $wpdb;return 'df-image-exec-'.substr(hash('sha256',(string)$wpdb->get_var('SELECT DATABASE()').'|'.$wpdb->prefix.'|'.$id),0,44);}
 public function send(string $id,array $wire,?OfflineImageTransport $simulator=null):array|WP_Error {
  $access=ImageGenerationAccess::authorize();if($access instanceof WP_Error)return $access;$journal=new ImageGenerationJournal();$job=$journal->read($id);if($job instanceof WP_Error)return $job;
  if($job['state']!=='DISPATCHING'||($job['usage_id']??0)<1)return self::error('dispatch_state_required');$identity=$job['identity'];$mode=$simulator===null?'NETWORK':'OFFLINE_SIMULATOR';
  $contract=$wire;unset($contract['body']);
  if(!is_string($wire['body']??null)||$identity['mode']!==$mode||Validator::canonicalJson($identity['wire_contract'])!==Validator::canonicalJson($contract)||($wire['body_sha256']??null)!==hash('sha256',$wire['body'])||!in_array($wire['url']??null,['https://api.openai.com/v1/images/generations','https://api.openai.com/v1/images/edits'],true))return self::error('request_conflict');
  $policy=(new ImageGenerationPolicyRepository())->resolve($identity['policy_sha256']);if($policy instanceof WP_Error)return $policy;$p=$policy['policy'];if($mode==='NETWORK'&&(!$p['enabled']||!ImageGenerationAccess::networkAllowed()))return self::error('network_locked');
  if($mode==='NETWORK'&&$this->custody===null)return self::error('credential_custody_unavailable');
  $hold=(new MonetaryReservationRepository())->reservation((int)$job['usage_id']);if($hold instanceof WP_Error)return $hold;if(!is_array($hold)||$hold['shop_key']!=='personalized_pod'||$hold['request_sha256']!==$wire['request_sha256']||$hold['model']!==$wire['model'])return self::error('reservation_required');
  $quote=(new MonetaryReservationRepository())->findQuote('personalized_pod',['request_sha256'=>$wire['request_sha256'],'model'=>$wire['model']],false);if($quote instanceof WP_Error)return $quote;if(($quote['quote_id']??null)!==$hold['quote_id'])return self::error('quote_changed');
  $options=['method'=>'POST','timeout'=>$p['timeout_seconds'],'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'decompress'=>false,'limit_response_size'=>4*(int)ceil($p['max_output_bytes']/3)+32769,'headers'=>['Content-Type'=>$wire['content_type'],'Accept-Encoding'=>'identity'],'body'=>$wire['body']];
  // A Hostinger/PHP timeout cannot be treated as proof the provider did not execute.
  if($mode==='NETWORK'){$maximum=(int)ini_get('max_execution_time');if($maximum>0&&$maximum<$p['timeout_seconds']+10)return self::error('host_fail_stop_required');}
  global $wpdb;$lock=self::lockName($id);$wpdb->last_error='';$got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if($wpdb->last_error!==''||(string)$got!=='1')return self::error('busy');
  try{
   $current=$journal->read($id);if($current instanceof WP_Error||$current['event_sha256']!==$job['event_sha256'])return self::error('stale_dispatch');$claim=$this->claim($job,$mode);if($claim instanceof WP_Error)return $claim;
   if($simulator!==null)$response=$simulator->send($wire,$options);
   else {
    // Double gate before key access, then recheck policy/role/generation immediately before HTTP.
    $invoked=false;
    $response=$this->custody->withExclusiveGeneration(['job_id'=>$id,'identity'=>$identity,'policy'=>$policy],function()use(&$invoked,$p,$identity,$wire,$options){
     if($invoked)return self::error('dispatch_already_claimed_or_unknown');$invoked=true;
     if(!ImageGenerationAccess::networkAllowed())return self::error('network_locked');$fresh=(new ImageGenerationPolicyRepository())->resolve($identity['policy_sha256']);if($fresh instanceof WP_Error)return self::error('credential_generation_changed');$secret=$this->secret($p['integration_id']);if($secret instanceof WP_Error)return $secret;
     try{
      $options['headers']['Authorization']='Bearer '.$secret;
      $response=wp_remote_post($wire["url"],$options);
      return $response;
     }finally{unset($secret,$options['headers']['Authorization']);}
    });
    if(!$invoked&& !($response instanceof WP_Error))return self::error('custody_dispatch_unconfirmed');
   }
   if($response instanceof WP_Error)return self::error('transport_unknown');$status=(int)wp_remote_retrieve_response_code($response);$body=wp_remote_retrieve_body($response);$requestId=(string)wp_remote_retrieve_header($response,'x-request-id');$encoding=(string)wp_remote_retrieve_header($response,'content-encoding');
   if($encoding!==''&&strtolower($encoding)!=='identity')return self::error('encoding_unknown');if(!is_string($body)||strlen($body)>$options['limit_response_size']-1)return self::error('oversized_unknown');
   return ['status'=>$status,'body'=>$body,'request_id'=>$requestId,'mode'=>$mode,'provider_response_sha256'=>hash('sha256',$body),'external_execution_performed'=>$mode==='NETWORK','retry_permitted'=>false];
  }finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if($wpdb->last_error!==''||(string)$released!=='1')return self::error('release_unknown');}
 }
 private function claim(array $job,string $mode):true|WP_Error {
  global $wpdb;$name='df_shop2_image_send_'.$job['id'];$wpdb->last_error='';$old=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));if($wpdb->last_error!==''||$old!==null)return self::error('dispatch_already_claimed_or_unknown');
  $wpdb->last_error='';$auditCount=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','image_dispatch_claimed','image_dispatch',$job['id']));if($wpdb->last_error!==''||(string)$auditCount!=='0')return self::error('dispatch_already_claimed_or_unknown');
  $engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->options,Tables::audit_log()));if(count($engines)!==2||array_filter($engines,static fn($e)=>strtoupper($e)!=='INNODB')||$wpdb->query('START TRANSACTION')===false)return self::error('durability_unavailable');
  $record=['job_id'=>$job['id'],'event_sha256'=>$job['event_sha256'],'usage_id'=>$job['usage_id'],'request_sha256'=>$job['identity']['wire_contract']['request_sha256'],'mode'=>$mode,'claimed_by'=>get_current_user_id(),'claimed_at'=>gmdate('Y-m-d H:i:s')];$json=Validator::canonicalJson($record);$ok=$wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$json,'autoload'=>'no']);
  if($ok!==1||!Logger::write('image_dispatch_claimed',['job_id'=>$job['id'],'claim_sha256'=>hash('sha256',$json),'mode'=>$mode],'image_dispatch',$job['id'])){$wpdb->query('ROLLBACK');return self::error('claim_uncertain');}if($wpdb->query('COMMIT')===false)return self::error('commit_unknown');
  $wpdb->last_error='';$session=$wpdb->get_row('SELECT @@session.autocommit AS ac,@@session.in_transaction AS active',ARRAY_A);if($wpdb->last_error!==''||!is_array($session)||(string)$session['ac']!=='1'||(string)$session['active']!=='0')return self::error('commit_unknown');
  $wpdb->last_error='';$saved=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));if($wpdb->last_error!==''||$saved!==$json)return self::error('claim_uncertain');
  $wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s','image_dispatch_claimed','image_dispatch',$job['id']),ARRAY_A);$context=is_array($rows)&&count($rows)===1?json_decode((string)$rows[0]['context'],true):null;return $wpdb->last_error===''&&is_array($context)&&($context['claim_sha256']??null)===hash('sha256',$json)?true:self::error('claim_uncertain');
 }
 private function secret(int $id):string|WP_Error {
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT ciphertext,fingerprint FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s',$id,'api_key'),ARRAY_A);if($wpdb->last_error!==''||!is_array($row))return self::error('credential_unavailable');
  try{$key=CredentialVault::decryptExisting($row['ciphertext'],Integrations::secretContext($id,'api_key'));if($key===''||strlen($key)>4096||!hash_equals($row['fingerprint'],CredentialVault::fingerprint($key,Integrations::secretContext($id,'api_key'))))return self::error('credential_unavailable');return $key;}catch(\Throwable){return self::error('credential_unavailable');}
 }
 private static function error(string $reason):WP_Error{return new WP_Error('image_http_'.$reason,'Image dispatch is blocked or uncertain; retained reservation requires review. No automatic retry.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
