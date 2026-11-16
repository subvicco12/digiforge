<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use DigiForge\Security\ShopAccess;
use DigiForge\Core\Settings;
use WP_Error;

/** Fresh actor and signed Shop 2 admission; never changes a caller's transaction or switches. */
final class ImageGenerationAccess
{
 public static function authorize():true|WP_Error {
  global $wpdb;$wpdb->last_error='';$session=$wpdb->get_row('SELECT @@session.autocommit AS ac, @@session.in_transaction AS active',ARRAY_A);
  if($wpdb->last_error!==''||!is_array($session)||(string)$session['ac']!=='1'||(string)$session['active']!=='0')return ImageGenerationEvidenceStore::error('caller_transaction');
  $id=get_current_user_id();if($id<1)return ImageGenerationEvidenceStore::error('actor_required');
  clean_user_cache($id);wp_cache_delete($id,'user_meta');wp_cache_delete($wpdb->prefix.'user_roles','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');wp_roles()->for_site();$GLOBALS['current_user']=new \WP_User($id);
  foreach(['manage_digiforge_pod','manage_digiforge_ai']as $cap)if(!current_user_can($cap)||ShopAccess::authorize('personalized_pod',$cap)!==true)return ImageGenerationEvidenceStore::error('scope_required');
  return true;
 }
 public static function networkAllowed():bool {return self::authorize()===true&&defined('DIGIFORGE_IMAGE_NETWORK_EXECUTION_AUTHORIZED')&&DIGIFORGE_IMAGE_NETWORK_EXECUTION_AUTHORIZED===true&&Settings::is_enabled('ai')&&Settings::is_enabled('product_development')&&!Settings::safety_locked();}
}

/** Internal shared persistence primitives, loaded only through the image access gate. */
final class ImageGenerationEvidenceStore
{
 public static function error(string $code):WP_Error {return new WP_Error('image_generation_'.$code,'Current image evidence is unavailable or does not satisfy its immutable contract.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
 public static function hash(mixed $value):bool {return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
 public static function token(mixed $value):bool {return is_string($value)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D',$value)===1;}
 public static function digest(array $record):string {return hash('sha256',Validator::canonicalJson($record));}
 public static function seal(array $record,string $field):array {$record[$field]=self::digest($record);$record['_seal']=hash_hmac('sha256',Validator::canonicalJson($record),wp_salt('auth'));return $record;}
 public static function valid(array $record,string $field):bool {$seal=$record['_seal']??null;unset($record['_seal']);if(!self::hash($seal)||!hash_equals($seal,hash_hmac('sha256',Validator::canonicalJson($record),wp_salt('auth'))))return false;$hash=$record[$field]??null;unset($record[$field]);return self::hash($hash)&&hash_equals($hash,self::digest($record));}
 public static function load(string $key):array|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return self::error('store_unavailable');if($raw===null)return null;if(!is_string($raw)||strlen($raw)>131072)return self::error('store_corrupt');$value=json_decode($raw,true,32);return is_array($value)?$value:self::error('store_corrupt');}
 public static function put(string $key,array $value):true|WP_Error {global $wpdb;$json=Validator::canonicalJson($value);if(strlen($json)>131072)return self::error('record_bound');$wpdb->last_error='';$written=$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>$json,'autoload'=>'no']);if($written!==1||$wpdb->last_error!=='')return self::error('write_failed');$actual=self::load($key);return is_array($actual)&&Validator::canonicalJson($actual)===$json?true:self::error('write_unconfirmed');}
 public static function audit(string $kind,string $id,string $hash,string $state=''):true|WP_Error {global $wpdb;$wpdb->last_error='';$ok=$wpdb->insert(Tables::audit_log(),['event_type'=>'image_'.$kind,'actor_id'=>get_current_user_id(),'object_type'=>'image_generation','object_id'=>$id,'context'=>Validator::canonicalJson(['sha256'=>$hash,'state'=>$state]),'created_at'=>gmdate('Y-m-d H:i:s')],['%s','%d','%s','%s','%s','%s']);return $ok===1&&$wpdb->last_error===''&&self::audited($kind,$id,$hash,get_current_user_id(),$state)?true:self::error('audit_unconfirmed');}
 public static function audited(string $kind,string $id,string $hash,int $actor,string $state=''):bool {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col($wpdb->prepare('SELECT context FROM '.Tables::audit_log().' WHERE event_type=%s AND object_type=%s AND object_id=%s AND actor_id=%d LIMIT 100','image_'.$kind,'image_generation',$id,$actor));if($wpdb->last_error!==''||!is_array($rows))return false;$matches=0;foreach($rows as $raw){$value=json_decode((string)$raw,true);if(is_array($value)&&($value['sha256']??null)===$hash&&($value['state']??null)===$state)$matches++;}return $matches===1;}

 public static function locked(string $key,callable $work):array|WP_Error {
  global $wpdb;$name='df-image-'.substr(hash('sha256',$key),0,48);$wpdb->last_error='';$got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));if($wpdb->last_error!==''||(string)$got!=='1')return self::error('busy');$started=false;$commitAttempted=false;$result=self::error('transaction_unavailable');
  try{$wpdb->last_error='';$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$wpdb->options,Tables::audit_log()));if($wpdb->last_error!==''||!is_array($engines)||count($engines)!==2||array_filter($engines,static fn($e)=>strtoupper((string)$e)!=='INNODB'))$result=self::error('innodb_required');else{$wpdb->last_error='';if($wpdb->query('START TRANSACTION')!==false&&$wpdb->last_error===''){$started=true;$result=$work();$wpdb->last_error='';if($result instanceof WP_Error){if($wpdb->query('ROLLBACK')===false||$wpdb->last_error!=='')$result=self::error('rollback_unknown');$started=false;}else{$commitAttempted=true;$committed=$wpdb->query('COMMIT');$started=false;if($committed===false||$wpdb->last_error!=='')$result=self::error('commit_unknown');}}}}
  catch(\Throwable){$result=self::error('persistence_unknown');}
  finally{if($started&&!$commitAttempted){$wpdb->last_error='';if($wpdb->query('ROLLBACK')===false||$wpdb->last_error!=='')$result=self::error('rollback_unknown');}$wpdb->last_error='';$release=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));if($wpdb->last_error!==''||(string)$release!=='1')$result=self::error('release_unknown');}
  return $result;
 }
 public static function metadata(mixed $value,int $depth=0):bool {
  if($depth>8)return false;if(is_array($value)){if(count($value)>80)return false;foreach($value as $key=>$child){if($key==='storage_reference'&&(!is_string($child)||preg_match('~^digiforge/product-factory/pod-render-artifacts/shop-personalized_pod/[a-f0-9]{64}\.(?:png|jpg|svg|pdf)$~D',$child)!==1))return false;if(is_string($key)&&(\DigiForge\Security\Logger::isCredentialKey($key)||preg_match('/(?:base64|b64_json|ciphertext|binary|^png$|^body$|^prompt$)/i',$key)))return false;if(!self::metadata($child,$depth+1))return false;}return true;}
  return $value===null||is_bool($value)||is_int($value)||(is_string($value)&&strlen($value)<=4096&&preg_match('//u',$value)===1&&!str_contains($value,"\0")&&!str_starts_with($value,'/')&&!str_starts_with($value,'data:')&&preg_match('~^[A-Za-z]:[\\/]~',$value)!==1);
 }
}
