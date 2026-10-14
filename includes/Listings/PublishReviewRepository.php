<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use DigiForge\Security\Logger;
use WP_Error;
/** Separate expiring human publish decision. Local evidence never activates publishing. */
final class PublishReviewRepository {
 public const PREFIX='digiforge_etsy_publish_review_';
 public const USED='digiforge_etsy_publish_review_used_';
 public function inspect(array $input):array|WP_Error {
  $allowed=['shop_key','integration_id','shop_id','intent_id','draft_package_id'];if(array_diff(array_keys($input),$allowed)!==[])return self::error('input');
  $shop=$input['shop_key']??null;if(!is_string($shop)||!ShopAccessPolicy::validShop($shop)||!is_user_logged_in()||!current_user_can('manage_digiforge_connections')||!current_user_can('manage_digiforge_listings'))return self::error('access',403);
  foreach(['manage_digiforge_connections','manage_digiforge_listings']as $cap)if(is_wp_error(ShopAccess::authorize($shop,$cap)))return self::error('access',403);
  foreach(['integration_id','shop_id','intent_id','draft_package_id']as $key)if(!is_int($input[$key]??null)||$input[$key]<1)return self::error('input');
  global $wpdb;$wpdb->last_error='';$scope=$wpdb->get_row($wpdb->prepare('SELECT l.* FROM '.Tables::etsy_intents().' i INNER JOIN '.Tables::etsy_draft_packages().' p ON p.id=i.draft_package_id INNER JOIN '.Tables::listings().' l ON l.id=i.listing_id AND l.id=p.listing_id WHERE i.id=%d AND p.id=%d AND BINARY l.shop_reference=BINARY %s',$input['intent_id'],$input['draft_package_id'],$shop),ARRAY_A);
  if($wpdb->last_error!=='')return self::error('unavailable',503);if(!is_array($scope))return self::error('scope',403);
  $pre=(new EtsyPrepublishPreparation())->prepare($input['intent_id'],$input['draft_package_id']);if(is_wp_error($pre))return $pre;
  $qa=(new DraftQaReviewRepository())->verify($input['intent_id'],$input['draft_package_id'],(string)$input['shop_id']);if(is_wp_error($qa))return $qa;
  $identity=EtsyVerifiedShopIdentity::resolve($input['integration_id'],(string)$scope['shop_reference'],$input['shop_id'],(string)($scope['environment']??''));if(is_wp_error($identity))return $identity;
  $wpdb->last_error='';$integration=$wpdb->get_row($wpdb->prepare('SELECT provider,environment,status,enabled,config FROM '.Tables::integrations().' WHERE id=%d',$input['integration_id']),ARRAY_A);if($wpdb->last_error!==''||!is_array($integration))return self::error('unavailable',503);if($integration['environment']!==$scope['environment']||(int)$integration['enabled']!==1)return self::error('integration');
  if(($scope['state']??'')!=='APPROVED'||(int)($scope['approved_by']??0)<1||empty($scope['approved_at']))return self::error('approval');
  $parent=(new EtsyOperationRepository())->confirmedCreateForScope($input['intent_id'],$input['draft_package_id'],(string)$input['shop_id']);if(is_wp_error($parent))return $parent;if(!is_array($parent)||!ctype_digit((string)$parent['external_reference'])||(int)$parent['external_reference']<1)return self::error('parent');
  $plan=EtsyPublishListingOperation::plan($input['shop_id'],(int)$parent['external_reference']);if(is_wp_error($plan))return $plan;$fingerprint=EtsyRequestFingerprint::fromPayload($plan['payload']);if(is_wp_error($fingerprint))return $fingerprint;
  $evidence=['input'=>$input,'listing'=>$scope,'prepublish'=>$pre,'draft_qa'=>$qa,'integration_hash'=>hash('sha256',Validator::canonicalJson($integration)),'parent'=>$parent,'request_fingerprint'=>$fingerprint];
  return ['input'=>$input,'listing_id'=>(int)$scope['id'],'readiness_hash'=>$pre['readiness_hash'],'scope_hash'=>hash('sha256',Validator::canonicalJson($evidence)),'request_fingerprint'=>$fingerprint,'publish_authorized'=>false,'external_execution_authorized'=>false];
 }
 public function record(array $input,string $expected,string $confirmation):array|WP_Error {
  if($confirmation!=='CONFIRM_SEPARATE_PUBLISH_REVIEW')return self::error('confirmation');$context=$this->inspect($input);if(is_wp_error($context))return $context;if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($context['scope_hash'],$expected))return self::error('stale');
  $id=wp_generate_uuid4();$issued=time();$payload=['version'=>1,'review_id'=>$id,'actor_id'=>get_current_user_id(),'issued_at'=>$issued,'expires_at'=>$issued+300,'context'=>$context,'decision'=>'APPROVE'];$row=['payload'=>$payload,'seal'=>$this->seal($payload)];
  if(!Logger::write('etsy_separate_publish_review',['scope_hash'=>$expected,'review_id'=>$id],'listing',(string)$context['listing_id']))return self::error('audit',503);
  if(!add_option(self::PREFIX.$id,wp_json_encode($row),'',false))return self::error('store',503);$stored=$this->read($id);if(is_wp_error($stored))return $stored;
  return $stored+['publish_authorized'=>false,'external_execution_authorized'=>false];
 }
 public function verify(string $id,array $context):array|WP_Error {
  $record=$this->read($id);if(is_wp_error($record))return $record;$p=$record['payload'];$current=$this->inspect($p['context']['input']);if(is_wp_error($current))return $current;if(!hash_equals(Validator::canonicalJson($current),Validator::canonicalJson($context))||!hash_equals(Validator::canonicalJson($current),Validator::canonicalJson($p['context'])))return self::error('stale');if($p['actor_id']!==get_current_user_id()||$p['expires_at']<=time()||!hash_equals($p['context']['scope_hash'],$context['scope_hash']))return self::error('stale',409);
  return $record;
 }
 public function consume(string $id,array $context,string $key):array|WP_Error {
  $record=$this->verify($id,$context);if(is_wp_error($record))return $record;if(!preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D',$key))return self::error('idempotency');
  $receipt=wp_json_encode(['review_id'=>$id,'actor_id'=>get_current_user_id(),'scope_hash'=>$context['scope_hash'],'key_hash'=>hash('sha256',$key),'consumed_at'=>time()]);if(!add_option(self::USED.$id,$receipt,'',false))return self::error('replay');
  global $wpdb;$wpdb->last_error='';$stored=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',self::USED.$id));if($wpdb->last_error!==''||!is_string($stored)||!hash_equals($receipt,$stored))return self::error('consume_unavailable',503);
  if(!Logger::write('etsy_separate_publish_review_consumed',['review_id'=>$id,'scope_hash'=>$context['scope_hash']],'listing',(string)$context['listing_id']))return self::error('audit',503);
  return ['action'=>'ETSY_PUBLISH_LISTING','scope_hash'=>$context['scope_hash'],'state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$context['readiness_hash'],'review_id'=>$id,'approved_by'=>$record['payload']['actor_id'],'request_fingerprint'=>$context['request_fingerprint']];
 }
 public function status(string $id):array|WP_Error {
  $r=$this->read($id);if(is_wp_error($r))return $r;global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',self::USED.$id));if($wpdb->last_error!=='')return self::error('unavailable',503);
  $state='EXPIRED';if($raw!==null){$used=is_string($raw)?json_decode($raw,true):null;if(!is_array($used)||($used['review_id']??null)!==$id||($used['actor_id']??null)!==$r['payload']['actor_id']||($used['scope_hash']??null)!==$r['payload']['context']['scope_hash']||!preg_match('/^[a-f0-9]{64}$/D',(string)($used['key_hash']??''))||!is_int($used['consumed_at']??null)||$used['consumed_at']<$r['payload']['issued_at']||$used['consumed_at']>time())return self::error('consume_unavailable',503);$state='CONSUMED';}
  elseif($r['payload']['expires_at']>time()){$current=$this->verify($id,$r['payload']['context']);if(is_wp_error($current)){$data=$current->get_error_data();if(is_array($data)&&($data['status']??0)>=500)return $current;$state='STALE_OR_BLOCKED';}else $state='CURRENT_LOCAL_DECISION';}
  return ['state'=>$state,'expires_at'=>$r['payload']['expires_at'],'input'=>$r['payload']['context']['input'],'external_execution_authorized'=>false];
 }
 public function read(string $id):array|WP_Error {
  if(!is_user_logged_in()||!current_user_can('manage_digiforge_connections')||!current_user_can('manage_digiforge_listings'))return self::error('access',403);if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$id))return self::error('id');global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',self::PREFIX.$id));if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>65536)return self::error('unavailable',503);
  try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error('corrupt');}$p=$r['payload']??null;if(!is_array($p)||($p['version']??null)!==1||($p['review_id']??null)!==$id||($p['decision']??null)!=='APPROVE'||!is_int($p['actor_id']??null)||$p['actor_id']<1||!is_int($p['issued_at']??null)||!is_int($p['expires_at']??null)||$p['expires_at']-$p['issued_at']!==300||$p['issued_at']>time()||!is_array($p['context']??null)||!preg_match('/^[a-f0-9]{64}$/D',(string)($p['context']['scope_hash']??''))||!is_string($r['seal']??null)||!hash_equals($this->seal($p),$r['seal']))return self::error('corrupt');if($p['actor_id']!==get_current_user_id())return self::error('access',403);foreach(['manage_digiforge_connections','manage_digiforge_listings']as $cap)if(is_wp_error(ShopAccess::authorize((string)($p['context']['input']['shop_key']??''),$cap)))return self::error('access',403);return $r;
 }
 private function seal(array $p):string {return hash_hmac('sha256',Validator::canonicalJson($p),wp_salt('auth'));}
 private static function error(string $reason,int $status=409):WP_Error {return new WP_Error('etsy_publish_review_'.$reason,'Separate publish review '.$reason.'. No external authorization is inferred.',['status'=>$status,'external_execution_authorized'=>false]);}
}
