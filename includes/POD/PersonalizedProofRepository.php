<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Listings\Validator;
use DigiForge\Security\Logger;
use WP_Error;
/** Immutable private local preparation/review ledger. No provider or historical reservation writes. */
final class PersonalizedProofRepository {
 private const PREFIX='digiforge_native_proof_';
 private static function access():bool{return Shop2ProductWorkflow::access(true);}
 public static function error(string $code):WP_Error{return new WP_Error('personalized_proof_'.$code,'Personalized preparation blocked: '.$code.'.',['status'=>409,'external_execution_authorized'=>false]);}
 private function raw(string $key):string|WP_Error|null {global $wpdb;$wpdb->last_error='';$s=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$key));return $wpdb->last_error!==''?self::error('storage'):($s===null||is_string($s)?$s:self::error('storage'));}
 private function put(string $key,array $record,string $event,string $id):array|WP_Error {
  global $wpdb;$raw=Validator::canonicalJson($record);if(strlen($raw)>6000000)return self::error('size');
  foreach([$wpdb->options,\DigiForge\Database\Tables::audit_log()]as $table){$wpdb->last_error='';$engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));if($wpdb->last_error!==''||strtoupper((string)$engine)!=='INNODB')return self::error('transactional_storage_required');}
  if($wpdb->query('START TRANSACTION')===false)return self::error('transaction');
  $fail=static function($e)use($wpdb){$wpdb->query('ROLLBACK');return $e;};
  $old=$this->raw($key);if(is_wp_error($old))return $fail($old);
  if($old!==null){$wpdb->query('ROLLBACK');return hash_equals($old,$raw)?$record:self::error('immutable_conflict');}
  $wpdb->last_error='';$n=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$wpdb->options.' (option_name,option_value,autoload) VALUES (%s,%s,%s)',$key,$raw,'no'));
  if($n!==1||$wpdb->last_error!==''||!Logger::write($event,['record_sha256'=>hash('sha256',$raw),'proof_reference'=>$id,'external_execution_authorized'=>false],'personalized_proof',$id))return $fail(self::error('evidence_write'));
  if($wpdb->query('COMMIT')===false)return self::error('commit_unknown');return $this->raw($key)===$raw?$record:self::error('readback');
 }
 public function create(string $workflow,array $input,?string $parent=null):array|WP_Error {
  if(!self::access())return self::error('access');$link=(new Shop2ProductWorkflow())->read($workflow);if(is_wp_error($link))return $link;
  try{$prepared=PersonalizedProductPreparation::prepare($input,$link['review']);}catch(\Throwable $e){return self::error('invalid_input');}
  if(($prepared['fixture_id']==='FL-03'?'mug':'poster')!==$link['record']['family'])return self::error('family');
  if($parent!==null){$p=$this->read($parent);if(is_wp_error($p))return $p;if($p['workflow']!==$workflow||$p['prepared']['fixture_id']!==$prepared['fixture_id'])return self::error('parent_scope');}
  $record=['schema'=>'native-proof-ledger-v1','shop_key'=>'personalized_pod','workflow'=>$workflow,'parent'=>$parent,'prepared'=>$prepared,'external_execution_authorized'=>false];$hash=hash('sha256',Validator::canonicalJson($record));
  $saved=$this->put(self::PREFIX.$hash,$record,'personalized_proof_prepared',$hash);if(is_wp_error($saved))return $saved;$verified=$this->read($hash);return is_wp_error($verified)?$verified:['hash'=>$hash,'record'=>$verified];
 }
 public function read(string $hash):array|WP_Error {
  if(!self::access()||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('access_or_hash');$raw=$this->raw(self::PREFIX.$hash);if(!is_string($raw)||strlen($raw)>6000000||!hash_equals($hash,hash('sha256',$raw)))return self::error('integrity');
  try{$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error('integrity');}
  if(($r['schema']??null)!=='native-proof-ledger-v1'||($r['shop_key']??null)!=='personalized_pod'||($r['external_execution_authorized']??null)!==false)return self::error('scope');
  if(!is_string($r['workflow']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['workflow'])||!is_array($r['prepared']??null)||!is_array($r['prepared']['supplier']??null)||!is_string($r['prepared']['proof_svg']??null)||!is_string($r['prepared']['proof_sha256']??null)||!hash_equals($r['prepared']['proof_sha256'],hash('sha256',$r['prepared']['proof_svg']))||($r['prepared']['external_execution_authorized']??null)!==false)return self::error('integrity');
  $link=(new Shop2ProductWorkflow())->read($r['workflow']);if(is_wp_error($link))return $link;
  $expected=Validator::canonicalJson($link['review']);if(Validator::canonicalJson($r['prepared']['supplier'])!==$expected)return self::error('supplier_drift');
  global $wpdb;$wpdb->last_error='';$n=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".\DigiForge\Database\Tables::audit_log()." WHERE event_type='personalized_proof_prepared' AND object_type='personalized_proof' AND object_id=%s AND context LIKE %s",$hash,'%'.$wpdb->esc_like(hash('sha256',$raw)).'%'));if($wpdb->last_error!==''||(int)$n!==1)return self::error('audit_evidence');
  return $r;
 }
 public function personalize(string $hash,array $values):array|WP_Error {
  $r=$this->read($hash);if(is_wp_error($r))return $r;$p=$r['prepared'];
  $input=['shop_key'=>'personalized_pod','fixture_id'=>$p['fixture_id'],'template'=>$p['template_evidence'],'artwork'=>['sha256'=>$p['artwork_sha256'],'png_base64'=>$p['production']['fixed_artifact']['contents_base64'],'native_text_embedded'=>false],'values'=>$values];
  return $this->create($r['workflow'],$input,$hash);
 }
 public function queue():array|WP_Error {
  if(!self::access())return self::error('access');global $wpdb;$wpdb->last_error='';$keys=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s AND CHAR_LENGTH(option_name)=%d ORDER BY option_id DESC LIMIT 25',$wpdb->esc_like(self::PREFIX).'%',strlen(self::PREFIX)+64));if($wpdb->last_error!==''||!is_array($keys))return self::error('storage');$out=[];foreach($keys as $key){$hash=substr($key,strlen(self::PREFIX));if(!preg_match('/^[a-f0-9]{64}$/D',$hash))continue;$r=$this->read($hash);if(is_wp_error($r))return $r;$d=$this->decision($hash,$r['prepared']['proof_sha256']);$out[]=['hash'=>$hash,'fixture_id'=>$r['prepared']['fixture_id'],'template_version'=>$r['prepared']['template_version'],'review_state'=>is_wp_error($d)?'UNVERIFIED':($d===null?'PENDING':$d['decision'])];if(count($out)===25)break;}return $out;
 }
 private function decision(string $hash,string $proofSha):array|WP_Error|null {
  $raw=$this->raw(self::PREFIX.'decision_'.$hash);if(is_wp_error($raw)||$raw===null)return $raw;if(strlen($raw)>8192)return self::error('review_integrity');$d=json_decode($raw,true);
  if(!is_array($d)||($d['schema']??null)!=='native-proof-decision-v1'||!in_array($d['decision']??null,['APPROVE','REJECT'],true)||($d['proof_reference']??null)!==$hash||($d['proof_sha256']??null)!==$proofSha||!is_int($d['reviewer']??null)||$d['reviewer']<1||($d['external_execution_authorized']??null)!==false)return self::error('review_integrity');
  global $wpdb;$wpdb->last_error='';$n=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".\DigiForge\Database\Tables::audit_log()." WHERE event_type='personalized_proof_reviewed' AND object_type='personalized_proof' AND object_id=%s AND context LIKE %s",$hash,'%'.$wpdb->esc_like(hash('sha256',$raw)).'%'));if($wpdb->last_error!==''||(int)$n!==1)return self::error('review_audit');return $d;
 }
 public function decide(string $hash,string $decision,string $note):array|WP_Error {
  $r=$this->read($hash);if(is_wp_error($r))return $r;if(!in_array($decision,['APPROVE','REJECT'],true)||trim($note)===''||strlen($note)>1000)return self::error('human_review');
  $record=['schema'=>'native-proof-decision-v1','proof_reference'=>$hash,'proof_sha256'=>$r['prepared']['proof_sha256'],'decision'=>$decision,'reviewer'=>get_current_user_id(),'note'=>$note,'external_execution_authorized'=>false];
  $saved=$this->put(self::PREFIX.'decision_'.$hash,$record,'personalized_proof_reviewed',$hash);if(is_wp_error($saved))return $saved;return $this->decision($hash,$r['prepared']['proof_sha256'])??self::error('review_missing');
 }
 public function export(string $hash,string $orderLineReference,?string $package=null):array|WP_Error {
  $r=$this->read($hash);if(is_wp_error($r))return $r;if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$orderLineReference))return self::error('order_reference');
  $decision=$this->decision($hash,$r['prepared']['proof_sha256']);if(is_wp_error($decision))return $decision;if($decision===null||$decision['decision']!=='APPROVE')return self::error('human_approval_required');$raw=Validator::canonicalJson($decision);
  $listing=null;if($package!==null){$link=(new Shop2ProductWorkflow())->read($r['workflow']);$json=is_wp_error($link)?$link:(new Shop2ProductWorkflow())->export($link,$package);if(is_wp_error($json))return $json;$listing=json_decode($json,true);if(($listing['package']['provider']['product_id']??null)!==$r['prepared']['product_id'])return self::error('listing_product');}
  $gates=array_values(array_diff($r['prepared']['remaining_gates'],['HUMAN_APPROVAL_REQUIRED']));if($listing===null)$gates[]='APPROVED_LISTING_PACKAGE_REQUIRED';
  return ['schema'=>'held-personalized-order-preparation-v1','shop_key'=>'personalized_pod','order_line_reference'=>$orderLineReference,'proof_reference'=>$hash,'review_sha256'=>hash('sha256',$raw),'prepared'=>$r['prepared'],'listing_package'=>$listing,'remaining_gates'=>$gates,'production_submit_ready'=>false,'etsy_publish_ready'=>false,'software_status'=>'READY_FOR_CONTROLLED_ACCEPTANCE','state'=>'BLOCKED','external_execution_authorized'=>false];
 }
}
