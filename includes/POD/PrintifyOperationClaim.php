<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;
/** Permanent creation intent across packages, approvals, tokens and connection revisions. */
final class PrintifyOperationClaim
{
 public static function claim(array $scope,string $operation,string $identity,string $fingerprint):true|WP_Error{
  $account=$scope['external_account_reference']??null;$shop=$scope['external_shop_reference']??null;
  if(!is_string($account)||$account===''||!is_string($shop)||$shop===''||$identity===''||strlen($identity)>512||!in_array($operation,['PRODUCT_CREATE','PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE','WEBHOOK_CREATE','IMAGE_ARCHIVE','SUPPORT_REPRINT','SUPPORT_REFUND','SUPPORT_ADDRESS_CHANGE'],true)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))return self::error('scope');
  $durable=PrintifyProductPipeline::durableDispatchSession();if(is_wp_error($durable))return $durable;
  $hash=hash('sha256',json_encode([$account,$shop,$operation,$identity],JSON_THROW_ON_ERROR));$name='digiforge_pfy_intent_'.$hash;$lock='dfpfyi:'.substr($hash,0,56);global $wpdb;$wpdb->last_error='';
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1'||$wpdb->last_error!=='')return self::error('busy');$started=false;
  try{
   $wpdb->last_error='';$prior=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));$anchors=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s LIMIT 1','printify_intent',$hash));if($wpdb->last_error!==''||!is_array($anchors))return self::error('evidence');if($prior!==null||$anchors!==[])return self::error('consumed');
   if($wpdb->query('START TRANSACTION')===false)return self::error('transaction');$started=true;
   $record=['intent_hash'=>$hash,'operation'=>$operation,'request_fingerprint'=>$fingerprint,'actor_id'=>get_current_user_id(),'created_at'=>gmdate('c')];
   if($wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>wp_json_encode($record),'autoload'=>'no'])!==1||!Logger::write('printify_intent_claimed',$record,'printify_intent',$hash))return self::error('store');
   if($wpdb->query('COMMIT')===false)return self::error('commit_unknown');$started=false;return true;
  }catch(\Throwable){return self::error('persistence');}finally{if($started)$wpdb->query('ROLLBACK');$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 private static function error(string $code):WP_Error{return new WP_Error('printify_intent_'.$code,'Creation intent is consumed or its durable evidence is unavailable.',['status'=>409,'retry_permitted'=>false]);}
}
