<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Research\{DesignFactoryContract,DesignFactoryRepository};
use DigiForge\Listings\Validator;
use WP_Error;
/** Private immutable PNG imports, bound to exact supplier linkage and approved artwork. */
final class Shop2MockupRepository {
 private function prefix(string $workflow,string $design):string{return 'digiforge_s2_media_'.hash('sha256',$workflow.$design).'_';}
 private function context(array $link,string $design):array|WP_Error {
  if(!Shop2ProductWorkflow::access())return Shop2ProductWorkflow::error('Mockup access denied.',403);
  $h=(new DesignFactoryRepository())->handoff('personalized_pod',$design);if(is_wp_error($h))return $h;
  $valid=(new Shop2ProductWorkflow())->context($link,$h['design_metadata']['commercial_hash']);return is_wp_error($valid)?$valid:$h;
 }
 public function import(array $link,string $design,string $encoded,string $note):array|WP_Error {
  $h=$this->context($link,$design);if(is_wp_error($h))return $h;
  if(strlen($encoded)>2796204||trim($note)===''||strlen($note)>2000)return Shop2ProductWorkflow::error('A PNG up to 2 MiB and a bounded source/rights note are required.');
  $bytes=base64_decode($encoded,true);if(!is_string($bytes)||base64_encode($bytes)!==$encoded)return Shop2ProductWorkflow::error('Canonical PNG base64 required.');
  try{$meta=DesignFactoryContract::mockup($bytes);}catch(\Throwable){return Shop2ProductWorkflow::error('Complete bounded PNG with valid chunks, CRCs and image data required.');}
  $existing=$this->media($link,$design);if(is_wp_error($existing))return $existing;if(count($existing)>=10)return Shop2ProductWorkflow::error('Maximum ten mockups per exact design; create a design revision for replacements.');
  $r=['schema'=>'shop2-mockup-v1','supplier_workflow_hash'=>$link['hash'],'factory_hash'=>$design,'design_sha256'=>hash('sha256',$h['artwork']['record']['input']['artwork']['svg']),'image'=>$meta,'base64'=>$encoded,'note'=>$note,'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'supplier_authenticity'=>'UNVERIFIED','external_execution_authorized'=>false];
  $raw=Validator::canonicalJson($r);$hash=hash('sha256',$raw);
  if(!\DigiForge\Security\Logger::write('shop2_mockup_import_attempt',['workflow_hash'=>$link['hash'],'image_sha256'=>$meta['sha256'],'record_sha256'=>$hash],'shop2_mockup',$hash))return Shop2ProductWorkflow::error('Mockup audit unavailable.');
  if(!add_option($this->prefix($link['hash'],$design).$hash,$raw,'',false))return Shop2ProductWorkflow::error('Mockup exists or write uncertain; inspect its hash.');
  return ['reference'=>'shop2-mockups/'.$hash.'.png','sha256'=>$meta['sha256'],'design_sha256'=>$r['design_sha256']];
 }
 public function media(array $link,string $design):array|WP_Error {
  $h=$this->context($link,$design);if(is_wp_error($h))return $h;
  global $wpdb;$wpdb->last_error='';$prefix=$this->prefix($link['hash'],$design);$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_name LIMIT 11',$wpdb->esc_like($prefix).'%'));
  if($wpdb->last_error!==''||!is_array($names)||count($names)>10)return Shop2ProductWorkflow::error('Mockup inventory unavailable or out of bounds.');
  $rows=[];foreach($names as $name){$hash=substr($name,strlen($prefix));$r=$this->read($link,$design,$hash);if(is_wp_error($r))return $r;$rows[]=['reference'=>'shop2-mockups/'.$hash.'.png','sha256'=>$r['image']['sha256'],'design_sha256'=>$r['design_sha256']];}return $rows;
 }
 public function read(array $link,string $design,string $hash):array|WP_Error {
  $h=$this->context($link,$design);if(is_wp_error($h))return $h;if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return Shop2ProductWorkflow::error('Exact mockup reference required.');
  global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$this->prefix($link['hash'],$design).$hash));if(class_exists(\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::class))\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::observeOption($this->prefix($link['hash'],$design).$hash,$raw);
  if($wpdb->last_error!==''||!is_string($raw)||strlen($raw)>2805000||!hash_equals($hash,hash('sha256',$raw)))return Shop2ProductWorkflow::error('Mockup bytes missing, corrupt or unavailable.');
  $r=json_decode($raw,true);if(!is_array($r)||($r['schema']??null)!=='shop2-mockup-v1'||($r['supplier_workflow_hash']??null)!==$link['hash']||($r['factory_hash']??null)!==$design||($r['external_execution_authorized']??null)!==false||($r['design_sha256']??null)!==hash('sha256',$h['artwork']['record']['input']['artwork']['svg']))return Shop2ProductWorkflow::error('Mockup design/supplier binding conflict.');
  try{$bytes=base64_decode($r['base64'],true);if(!is_string($bytes)||base64_encode($bytes)!==$r['base64']||Validator::canonicalJson(DesignFactoryContract::mockup($bytes))!==Validator::canonicalJson($r['image']))throw new \InvalidArgumentException();}catch(\Throwable){return Shop2ProductWorkflow::error('Stored mockup integrity failed.');}return $r;
 }
}
