<?php
declare(strict_types=1);
require_once __DIR__.'/PortalAccessFixture.php';

/** Synthetic isolated tests only: real enrollment, signed delegation, audited independent review. */
final class PrintifyApiApprovalFixture
{
 public int $integration;
 public int $reviewer;
 public int $grantor;
 private array $snapshots=[];
 private array $options=[];
 private array $ownerCookies=[];
 private const PREFIXES=['digiforge_portal_','digiforge_shop_access_','digiforge_local_review_','digiforge_api_read_','digiforge_pfy_lifecycle_','digiforge_execution_nonce_','digiforge_pfy_intent_','df_pq_','df_catalog_'];
 public static function checked(mixed $result):mixed { if(is_wp_error($result))throw new RuntimeException($result->get_error_code().': '.$result->get_error_message());if($result===false)throw new RuntimeException('Synthetic fixture database write failed');return $result; }
 public function boot():void
 {
  global $wpdb;
  $tables=\DigiForge\Database\Tables::class;
  self::checked(\DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());
  if(!has_action('digiforge_log',[\DigiForge\Security\Logger::class,'write']))add_action('digiforge_log',[\DigiForge\Security\Logger::class,'write'],10,4);
  foreach([$tables::settings(),$tables::integrations(),$tables::integration_secrets(),$tables::businesses(),$tables::stores(),$tables::product_programs(),$tables::audit_log(),$tables::pod_execution_nonces(),\DigiForge\Database\ProviderConnectionSchema::table()] as $table)$this->snapshots[$table]=$wpdb->get_results('SELECT * FROM '.$table,ARRAY_A);
  foreach(self::PREFIXES as $prefix)foreach($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($prefix).'%'),ARRAY_A) as $row)$this->options[$row['option_name']]=$row;
  // Isolate synthetic connections from committed rows left by other integration fixtures.
  // Exact snapshots are restored below; the runtime ambiguity denial is unchanged.
  self::checked($wpdb->query('DELETE FROM '.$tables::integration_secrets()));
  self::checked($wpdb->query('DELETE FROM '.$tables::integrations()));
  PortalAccessFixture::reset();PortalAccessFixture::enroll();$this->ownerCookies=$_COOKIE;
  $this->reviewer=(int)self::checked(wp_insert_user(['user_login'=>'df_api_review_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']));
  $caps=\DigiForge\Core\Capabilities::ALL;$user=get_userdata($this->reviewer);foreach($caps as $cap)$user->add_cap($cap);
  $this->grantor=(int)self::checked(wp_insert_user(['user_login'=>'df_api_grantor_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']));$grantor=get_userdata($this->grantor);foreach($caps as $cap)$grantor->add_cap($cap);
  SyntheticShopAuthority::write([['actor_id'=>$this->grantor,'shop'=>'personalized_pod','caps'=>$caps,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','isolated API review '.$this->reviewer),'authority'=>'DELEGATE']]);
  PortalAccessFixture::login($this->grantor);$acl=new \DigiForge\Security\ShopAccessRepository();$state=self::checked($acl->read('personalized_pod'));
  self::checked($acl->grant($this->reviewer,'personalized_pod','administrator',$caps,time()+1800,wp_generate_uuid4(),$state['revision']));$this->owner();
  $now=current_time('mysql',true);$key='api-test-'.wp_generate_uuid4();
  self::checked($wpdb->insert($tables::businesses(),['business_key'=>$key,'display_name'=>'Synthetic API business','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]));$business=(int)$wpdb->insert_id;
  self::checked($wpdb->insert($tables::stores(),['business_id'=>$business,'store_key'=>$key,'display_name'=>'Synthetic API store','channel'=>'etsy','status'=>'ACTIVE','created_at'=>$now,'updated_at'=>$now]));$store=(int)$wpdb->insert_id;
  self::checked($wpdb->insert($tables::product_programs(),['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}','created_at'=>$now,'updated_at'=>$now]));
  $repo=new \DigiForge\Integrations\Repository();$i=self::checked($repo->create(['provider'=>'printify','environment'=>'test','connection_key'=>$key,'display_name'=>'Synthetic API connection','status'=>'CONFIGURED','enabled'=>false,'config'=>['shop_key'=>'personalized_pod','shop_id'=>'12','account_reference'=>$key,'catalog_read_scope'=>['business_id'=>(string)$business,'store_id'=>(string)$store,'product_program'=>'PERSONALIZED_POD']]]));$this->integration=$i['id'];
  self::checked($repo->storeSecret($this->integration,'personal_access_token','synthetic-api-material-'.wp_generate_uuid4()));self::checked($repo->setEnabled($this->integration,true));
  foreach(['printify.connection.read','printify.lifecycle.prepare'] as $capability){$connection=new \DigiForge\POD\ScopedProviderConnection();$p=self::checked($connection->propose('personalized_pod',['integration_id'=>$this->integration,'capability'=>$capability,'source_sha256'=>hash('sha256',$key.$capability),'evidence_reference'=>'local://isolated/printify-api','observed_at'=>gmdate('c',time()-1)],time(),time()+1800,wp_generate_uuid4(),0));PortalAccessFixture::login($this->reviewer);self::checked($connection->decide('personalized_pod',$this->integration,$capability,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->owner();}
  // Direct test storage only: production settings release paths remain unchanged.
  foreach(['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify_activation_authorized'=>true,'printify'=>true] as $setting=>$value)$this->setting($setting,$value);
  $wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
 }
 public function owner():void {wp_set_current_user(1);$_COOKIE=$this->ownerCookies;}
 /** New logical test operation only: real simulated email flow, never timestamp renewal.
  * Leave a five-minute budget under the unchanged ten-minute owner proof. A
  * new session invalidates earlier session-bound grants; callers must prepare anew.
  */
 public function refreshOwnerVerification():void {
  $this->owner();$s=WP_Session_Tokens::get_instance(1)->get(wp_get_session_token());
  if(\DigiForge\PortalAccess\EmailAuthentication::recent(1)&&is_array($s)&&is_int($s['digiforge_email_verified_at']??null)&&$s['digiforge_email_verified_at']>time()-300)return;
  PortalAccessFixture::verifyEmail();$this->ownerCookies=$_COOKIE;
  if(!\DigiForge\PortalAccess\EmailAuthentication::recent(1))throw new RuntimeException('Synthetic owner step-up unavailable.');
 }
 public function setting(string $key,bool $value):void { global $wpdb;$ok=$wpdb->replace(\DigiForge\Database\Tables::settings(),['setting_key'=>$key,'setting_value'=>wp_json_encode($value),'setting_type'=>\DigiForge\Core\Config::setting_type($key),'updated_at'=>current_time('mysql',true)]);if($ok===false)throw new RuntimeException('Synthetic settings unavailable'); }
 public function approve(object $workflow,array $plan,string $prefix):array
 {
  $this->owner();$payload=self::checked($workflow->approvalPayload($plan['hash']));$key=$prefix.':'.$plan['hash'];
  $p=self::checked(\DigiForge\Security\ReviewedLocalEvidence::propose('policy','personalized_pod',$key,$payload,time(),time()+1200,wp_generate_uuid4(),0));
  PortalAccessFixture::login($this->reviewer);self::checked(\DigiForge\Security\ReviewedLocalEvidence::decide('policy','personalized_pod',$key,$p['revision'],$p['proposal_hash'],'APPROVED',wp_generate_uuid4()));$this->owner();
  return self::checked($workflow->authorize($plan['hash']));
 }
 public function restore():void
 {
  global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');PortalAccessFixture::reset();
  // Preserve database strings exactly: WP globally infers object_id as an integer.
  foreach($this->snapshots as $table=>$rows){$wpdb->query('DELETE FROM '.$table);foreach($rows as $row)$wpdb->insert($table,$row,array_fill(0,count($row),'%s'));}
  foreach(self::PREFIXES as $prefix)$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($prefix).'%'));
  foreach($this->options as $row)$wpdb->insert($wpdb->options,$row);
  if(isset($this->reviewer)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->reviewer);if(isset($this->grantor))wp_delete_user($this->grantor);}
  wp_cache_flush();$wpdb->query('START TRANSACTION');
 }
 public static function order():array {return ['external_id'=>'synthetic-quote','line_items'=>[['product_id'=>'p1','variant_id'=>3,'quantity'=>1]],'shipping_method'=>1,'is_printify_express'=>false,'is_economy_shipping'=>false,'send_shipping_notification'=>false,'address_to'=>['first_name'=>'Synthetic','last_name'=>'Recipient','email'=>'synthetic@example.invalid','phone'=>'','country'=>'US','region'=>'CA','address1'=>'123 Fixture Street','address2'=>'','city'=>'Fixture','zip'=>'90210']];}
 public static function response(array $body,int $status=200):array {return ['response'=>['code'=>$status,'message'=>'Synthetic'],'headers'=>[],'body'=>wp_json_encode($body)];}
}
