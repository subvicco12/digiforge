<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Typed owner-direction declarations, not measurement evidence, artistic certification or execution grants. */
final class ReviewedAcceptanceContract
{
 private const CRITERIA=['BP-seam-panel-render','BP-ai-value-routing','BP-template-qa-spelling','BP-portal-7-supplier-readiness','BP-portal-17-conversion','BP-portal-17-supplier-performance','BP-portal-17-product-performance','BP-portal-17-shop-performance','BP-portal-17-defect-quality','BP-quality-feedback'];
 /** Safe metadata only; referenced records and measurements remain unverified. */
 public static function criteria():array {return self::CRITERIA;}
 public static function status(string $shop,string $criterion,string $scope):array|WP_Error {
  $key=self::key($shop,$criterion,$scope);if(is_wp_error($key))return $key;
  $state=ReviewedLocalEvidence::read('policy',$shop,$key);if(is_wp_error($state))return $state;
  $view=['shop_key'=>$shop,'criterion'=>$criterion,'scope'=>$scope,'status'=>'MISSING','review_status'=>'UNKNOWN','review_revision'=>null,'effective_at'=>null,'expires_at'=>null,'revoked'=>false,'evidence_references'=>[],'value_state'=>'UNKNOWN','evidence_state'=>'UNVERIFIED','read_only'=>true,'external_execution_authorized'=>false,'supplier_approved'=>false,'publishing_allowed'=>false,'ai_execution_allowed'=>false];
  if($state===null)return $view;
  if(self::validate($shop,$state['payload'])!==$key){$view['status']='INVALID_EVIDENCE';return $view;}
  $view['review_status']=$state['status'];$view['status']=$state['status'];$view['review_revision']=$state['revision'];$view['effective_at']=$state['effective_at'];$view['expires_at']=$state['expires_at'];$view['revoked']=$state['status']==='REVOKED';
  $refs=[];$collect=static function(array $node)use(&$collect,&$refs):void {
   if(isset($node['shop_key'],$node['reference'],$node['sha256'])){$refs[$node['reference']]=$node;return;}
   foreach($node as $child)if(is_array($child))$collect($child);
  };$collect($state['payload']['definition']);$view['evidence_references']=array_values($refs);
  if($state['status']==='APPROVED'){
   if($state['expires_at']<=time())$view['status']='EXPIRED';
   elseif($state['effective_at']>time())$view['status']='NOT_EFFECTIVE';
   else{$current=ReviewedLocalEvidence::approved('policy',$shop,$key);$view['status']=!is_wp_error($current)&&$current===$state?'CURRENT_REVIEW':'REVIEW_STALE';}
  }
  return $view;
 }
 public static function propose(string $shop,array $input,int $effectiveAt,int $expiresAt,string $requestId,int $revision):array|WP_Error {
  $key=self::validate($shop,$input);if(is_wp_error($key))return $key;
  return self::withKey(ReviewedLocalEvidence::propose('policy',$shop,$key,$input,$effectiveAt,$expiresAt,$requestId,$revision),$key);
 }
 public static function decide(string $shop,string $criterion,string $scope,int $revision,string $hash,string $decision,string $requestId):array|WP_Error {
  $key=self::key($shop,$criterion,$scope);if(is_wp_error($key))return $key;
  $state=ReviewedLocalEvidence::read('policy',$shop,$key);if(is_wp_error($state))return $state;
  if(!is_array($state)||self::validate($shop,$state['payload'])!==$key)return self::error();
  return self::withKey(ReviewedLocalEvidence::decide('policy',$shop,$key,$revision,$hash,$decision,$requestId),$key);
 }
 public static function current(string $shop,string $criterion,string $scope):array|WP_Error {
  $key=self::key($shop,$criterion,$scope);if(is_wp_error($key))return $key;
  $state=ReviewedLocalEvidence::approved('policy',$shop,$key);if(is_wp_error($state))return $state;
  if(self::validate($shop,$state['payload'])!==$key)return self::error();
  return $state+['contract_key'=>$key,'value_state'=>'UNKNOWN','evidence_state'=>'UNVERIFIED','external_execution_authorized'=>false,'supplier_approved'=>false,'publishing_allowed'=>false,'ai_execution_allowed'=>false];
 }
 /** Exact reviewed text/locale parity only; this does not perform or certify linguistic QA. */
 public static function matchesText(string $shop,string $scope,string $text,string $locale):true|WP_Error {
  $state=self::current($shop,'BP-template-qa-spelling',$scope);if(is_wp_error($state))return $state;
  $d=$state['payload']['definition'];
  return strlen($text)<=65536&&preg_match('//u',$text)===1&&$locale===$d['locale']&&hash_equals($d['text_sha256'],hash('sha256',$text))?true:self::error();
 }
 private static function validate(string $shop,array $input):string|WP_Error {
  if(!self::keys($input,['version','criterion','scope','workflow','definition'])||($input['version']??null)!==1||!is_string($input['criterion'])||!is_string($input['scope'])||!self::identifier($input['workflow'])||!is_array($input['definition']))return self::error();
  $key=self::key($shop,$input['criterion'],$input['scope']);if(is_wp_error($key))return $key;$d=$input['definition'];
  switch($input['criterion']){
   case 'BP-seam-panel-render':
    if(!self::keys($d,['fixture_refs','geometry_ref','checks'])||!self::refs($shop,$d['fixture_refs'])||!self::ref($shop,$d['geometry_ref'])||!is_array($d['checks'])||!array_is_list($d['checks'])||count($d['checks'])!==3)return self::error();
    $checks=$d['checks'];sort($checks);if($checks!==['bleed','panel','seam'])return self::error();break;
   case 'BP-template-qa-spelling':
    if(!self::keys($d,['locale','text_sha256','spelling_review_ref'])||!is_string($d['locale'])||!preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/D',$d['locale'])||strlen($d['locale'])>64||!self::digest($d['text_sha256'])||!self::ref($shop,$d['spelling_review_ref']))return self::error();break;
   case 'BP-ai-value-routing':
    if(!self::keys($d,['measure','unit','baseline_ref','candidate_ref','benefit_review_ref','decision_rule_ref','fallback'])||!self::label($d['measure'])||!self::label($d['unit'])||!in_array($d['fallback'],['ORIGINAL','DETERMINISTIC'],true))return self::error();
    foreach(['baseline_ref','candidate_ref','benefit_review_ref','decision_rule_ref']as $field)if(!self::ref($shop,$d[$field]))return self::error();break;
   default:
    $fields=['numerator','denominator','population','attribution','period_start','period_end','source_records','formula_ref'];
    $quality=in_array($input['criterion'],['BP-portal-17-defect-quality','BP-quality-feedback'],true);if($quality)$fields[]='severity_policy_ref';
    if(!self::keys($d,$fields))return self::error();
    foreach(['numerator','denominator','population','attribution']as $field)if(!self::label($d[$field]))return self::error();
    $start=self::instant($d['period_start']);$end=self::instant($d['period_end']);
    if($start===null||$end===null||$start>=$end||!self::refs($shop,$d['source_records'])||($d['formula_ref']!==null&&!self::ref($shop,$d['formula_ref']))||($quality&&!self::ref($shop,$d['severity_policy_ref'])))return self::error();
  }
  return $key;
 }
 private static function key(string $shop,string $criterion,string $scope):string|WP_Error {
  if(!in_array($shop,['digital','personalized_pod'],true)||!in_array($criterion,self::CRITERIA,true)||!self::identifier($scope)||($shop==='digital'&&in_array($criterion,['BP-seam-panel-render','BP-template-qa-spelling'],true)))return self::error();
  $access=ShopAccess::authorize($shop,'manage_digiforge');if(is_wp_error($access))return $access;
  return 'acceptance:v1:'.$criterion.':'.$scope;
 }
 private static function refs(string $shop,mixed $refs):bool {
  if(!is_array($refs)||!array_is_list($refs)||count($refs)<1||count($refs)>32)return false;
  $seen=[];foreach($refs as $ref){if(!self::ref($shop,$ref))return false;$id=$ref['reference'];if(isset($seen[$id]))return false;$seen[$id]=true;}return true;
 }
 private static function ref(string $shop,mixed $ref):bool {
  if(!is_array($ref)||!self::keys($ref,['shop_key','reference','sha256'])||$ref['shop_key']!==$shop||!self::digest($ref['sha256'])||!is_string($ref['reference'])||!preg_match('#^local://[A-Za-z0-9][A-Za-z0-9/_.:-]{0,230}$#D',$ref['reference']))return false;
  foreach(explode('/',substr($ref['reference'],8))as $part)if($part==='.'||$part==='..')return false;return true;
 }
 private static function instant(mixed $value):?int {
  if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D',$value))return null;
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$value));$errors=\DateTimeImmutable::getLastErrors();
  return $date&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))?$date->getTimestamp():null;
 }
 private static function keys(array $value,array $keys):bool {$actual=array_keys($value);sort($actual);sort($keys);return $actual===$keys;}
 private static function identifier(mixed $value):bool{return is_string($value)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D',$value)===1;}
 private static function label(mixed $value):bool{return is_string($value)&&strlen($value)<=1024&&trim($value)!==''&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value);}
 private static function digest(mixed $value):bool{return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
 private static function withKey(array|WP_Error $value,string $key):array|WP_Error{return is_wp_error($value)?$value:$value+['contract_key'=>$key];}
 private static function error():WP_Error{return new WP_Error('acceptance_contract_invalid','A typed, scoped, independently reviewed local contract is required; evidence remains unverified.',['status'=>409,'external_execution_authorized'=>false]);}
}
