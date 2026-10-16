<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
use WP_Error;

/** Immutable human draft review, bound to current local readiness and recorded external ancestry. */
final class DraftQaReviewRepository {
 public const ACTION='digiforge_portal_draft_qa_review';
 public function inspect(int $createOperationId):array|WP_Error {
  if(!current_user_can('manage_digiforge_listings')||get_current_user_id()<1)return self::error('access','An authenticated listing reviewer is required.',403);
  $create=(new EtsyOperationRepository())->find($createOperationId);if($create instanceof WP_Error)return $create;
  if(!is_array($create)||($create['operation_type']??'')!=='CREATE_DRAFT'||($create['state']??'')!=='CONFIRMED_SUCCESS'||!ctype_digit((string)($create['external_reference']??''))||(int)$create['external_reference']<1||!ctype_digit((string)($create['shop_reference']??''))||(int)$create['shop_reference']<1||!preg_match('/^[a-f0-9]{64}$/D',(string)($create['request_fingerprint']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($create['evidence_hash']??'')))return self::error('draft','Confirmed draft identity is required.');
  global $wpdb;$intent=$this->row(Tables::etsy_intents(),(int)$create['intent_id']);if($intent instanceof WP_Error)return $intent;$package=$this->row(Tables::etsy_draft_packages(),(int)$create['draft_package_id']);if($package instanceof WP_Error)return $package;
  if(($intent['state']??'')!=='APPROVED_INTENT'||(int)$intent['draft_package_id']!==(int)$package['id']||(int)$intent['listing_id']!==(int)$package['listing_id'])return self::error('scope','Approved draft intent and package must match.');
  $listing=$this->row(Tables::listings(),(int)$package['listing_id']);if($listing instanceof WP_Error)return $listing;
  if(($listing['state']??'')!=='APPROVED'||(int)($listing['approved_by']??0)<1||empty($listing['approved_at'])||(int)($package['approved_by']??0)<1||empty($package['approved_at'])||($listing['environment']??'')!==($intent['environment']??''))return self::error('approval','Current human-approved listing/package and environment are required.');
  $release=(new ReleaseEvidence())->verify((int)$package['id']);if($release instanceof WP_Error)return $release;
  $current=(new Repository())->readiness((int)$listing['id']);if($current instanceof WP_Error)return $current;
  $digital=($listing['shop_reference']??'')==='digital'||in_array($current['product_mode']??'', ['digital','hybrid'],true);$attachment=null;
  if($digital){$wpdb->last_error='';$attachment=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'digiforge_etsy_operations WHERE intent_id=%d AND draft_package_id=%d AND shop_reference=%s AND operation_type=%s AND state=%s AND resource_reference=%s ORDER BY id DESC LIMIT 1',(int)$intent['id'],(int)$package['id'],(string)$create['shop_reference'],'UPLOAD_FILE','CONFIRMED_SUCCESS',(string)$create['external_reference']),ARRAY_A);if($wpdb->last_error!=='')return self::error('evidence_unavailable','Digital attachment evidence is unavailable.',503);
   if(!is_array($attachment)||!ctype_digit((string)($attachment['external_asset_reference']??''))||(int)$attachment['external_asset_reference']<1||(string)($attachment['external_reference']??'')!==(string)$create['external_reference']||!preg_match('/^[a-f0-9]{64}$/D',(string)($attachment['request_fingerprint']??'')))return self::error('attachment','Confirmed same-draft digital file attachment identity is required.');
  }
  $material=['listing'=>$listing,'intent'=>$intent,'package'=>$package,'readiness'=>$current,'create'=>$create,'attachment'=>$attachment];$hash=hash('sha256',(string)wp_json_encode($material));
  return ['listing_id'=>(int)$listing['id'],'intent_id'=>(int)$intent['id'],'draft_package_id'=>(int)$package['id'],'create_operation_id'=>$createOperationId,'shop_key'=>(string)$listing['shop_reference'],'shop_reference'=>(string)$create['shop_reference'],'environment'=>(string)$listing['environment'],'readiness_hash'=>(string)$release['readiness_hash'],'objective_evidence_hash'=>$hash,'attachment_operation_id'=>(int)($attachment['id']??0),'external_listing_id'=>(string)$create['external_reference'],'listing_title'=>(string)$listing['title'],'publish_authorized'=>false,'external_execution_performed'=>false];
 }
 public function record(int $intentId,int $packageId,int $createOperationId,array $checks,string $confirmation,string $expectedObjectiveHash):array|WP_Error {
  if($confirmation!=='CONFIRM_DRAFT_QA_REVIEW')return self::error('confirmation','Explicit draft QA review confirmation is required.');
  $context=$this->inspect($createOperationId);if($context instanceof WP_Error)return $context;
  if($context['intent_id']!==$intentId||$context['draft_package_id']!==$packageId||!preg_match('/^[a-f0-9]{64}$/D',$expectedObjectiveHash)||!hash_equals($context['objective_evidence_hash'],$expectedObjectiveHash))return self::error('stale','Reviewed target dependencies changed or scope does not match.');
  if(count($checks)!==7||array_diff(array_keys($checks),['title','description','seo','media','pricing','policy','personalization'])!==[])return self::error('checks','Exactly seven human review assertions are required.');foreach($checks as $status)if(!is_string($status)||!in_array($status,['PASS','WAIVED','FAIL'],true))return self::error('checks','Explicit PASS, WAIVED or FAIL human assertions are required.');
  $qa=DraftQaGate::evaluate($checks);if($qa instanceof WP_Error)return $qa;
  $material=['schema'=>'draft-qa-review-v1','context'=>$context,'checks'=>$qa['checks'],'passed'=>$qa['passed'],'reviewed_by'=>get_current_user_id(),'assertion_source'=>'AUTHENTICATED_HUMAN_REVIEW','commercial_semantic_truth_certified'=>false,'reviewed_at'=>current_time('mysql',true)];$identity=hash('sha256',(string)wp_json_encode($material));$key=$this->prefix($intentId,$packageId,$createOperationId).$identity;
  global $wpdb;$wpdb->last_error='';$existing=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return self::error('evidence_unavailable','Review replay evidence is unavailable.',503);
  if($existing!==null){$old=$this->decode($key,$existing);return $old instanceof WP_Error?$old:$old+['idempotent_replay'=>true];}
  $document=$material+['identity_sha256'=>$identity,'publish_authorized'=>false,'external_execution_performed'=>false];$encoded=wp_json_encode($document);if(!is_string($encoded)||strlen($encoded)>32768)return self::error('invalid','Review evidence exceeds its bounded schema.');
  if(!add_option($key,$encoded,'',false))return self::error('persistence','Immutable draft QA review could not be persisted.',503);
  $wpdb->last_error='';$stored=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!==''||!is_string($stored)||!hash_equals($encoded,$stored))return self::error('persistence','Draft QA persistence confirmation is unavailable.',503);
  return $document;
 }
 public function verify(int $intentId,int $packageId,string $shopReference):array|WP_Error {
  $create=(new EtsyOperationRepository())->confirmedCreateForScope($intentId,$packageId,$shopReference);if($create instanceof WP_Error)return $create;if(!is_array($create))return self::error('draft','Confirmed draft identity is required.');
  $context=$this->inspect((int)$create['id']);if($context instanceof WP_Error)return $context;
  global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT option_name,option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1',$wpdb->esc_like($this->prefix($intentId,$packageId,(int)$create['id'])).'%'),ARRAY_A);if($wpdb->last_error!=='')return self::error('evidence_unavailable','Draft QA review evidence is unavailable.',503);if(!is_array($row))return self::error('missing','A current human draft QA review is required before publish authorization.');
  $document=$this->decode((string)$row['option_name'],$row['option_value']);if($document instanceof WP_Error)return $document;
  if(!hash_equals((string)($document['context']['objective_evidence_hash']??''),$context['objective_evidence_hash'])||$document['context']!==$context)return self::error('stale','Draft QA no longer matches current reviewed dependencies.');
  $qa=DraftQaGate::evaluate($document['checks']);if($qa instanceof WP_Error)return $qa;if(!$qa['passed']||($document['passed']??null)!==true)return self::error('failed','Draft QA includes failed checks.');
  return $document;
 }
 private function decode(string $key,mixed $raw):array|WP_Error {
  if(!is_string($raw)||strlen($raw)>32768)return self::error('corrupt','Bounded review evidence is corrupt.');$d=json_decode($raw,true);if(!is_array($d)||($d['schema']??'')!=='draft-qa-review-v1'||!is_array($d['context']??null)||!is_array($d['checks']??null)||!is_int($d['reviewed_by']??null)||$d['reviewed_by']<1||($d['assertion_source']??'')!=='AUTHENTICATED_HUMAN_REVIEW'||($d['publish_authorized']??null)!==false||($d['external_execution_performed']??null)!==false)return self::error('corrupt','Review identity is corrupt.');
  $material=$d;unset($material['identity_sha256'],$material['publish_authorized'],$material['external_execution_performed']);$hash=hash('sha256',(string)wp_json_encode($material));if(!hash_equals((string)($d['identity_sha256']??''),$hash)||!str_ends_with($key,$hash))return self::error('corrupt','Review identity hash does not verify.');return $d;
 }
 private function row(string $table,int $id):array|WP_Error {global $wpdb;if($id<1)return self::error('scope','Positive persisted identity is required.');$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);return $wpdb->last_error!==''?self::error('evidence_unavailable','Draft review dependencies are unavailable.',503):(is_array($row)?$row:self::error('scope','Draft review dependency is missing.'));}
 private function prefix(int $intent,int $package,int $create):string{return 'digiforge_draft_qa_'.$intent.'_'.$package.'_'.$create.'_';}
 private static function error(string $code,string $message,int $status=409):WP_Error{return new WP_Error('digiforge_draft_qa_review_'.$code,$message,['status'=>$status]);}
}
