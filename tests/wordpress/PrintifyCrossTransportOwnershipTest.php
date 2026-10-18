<?php
declare(strict_types=1);
require_once __DIR__.'/Support/ReviewedLocalFixture.php';
require_once __DIR__.'/Support/ReviewedOwnershipFixture.php';
require_once __DIR__.'/Support/ScopedProviderConnectionFixture.php';
use DigiForge\POD\PrintifyConnectionBoundary as Boundary;
use DigiForge\Database\Tables;
final class PrintifyCrossTransportOwnershipTest extends WP_UnitTestCase {
 protected function tearDown():void {global $wpdb;$wpdb->query('SET autocommit=0');parent::tearDown();}
 private function fixture(string $capability='PROVIDER_ORDER_SUBMIT'):array {
  DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();$actors=ReviewedLocalFixture::actors('personalized_pod');$old=ReviewedOwnershipFixture::create($this);$id=$old['integration_id'];$repo=new DigiForge\Integrations\Repository();self::assertTrue($repo->storeSecret($id,'personal_access_token','synthetic-cross-transport-material'));self::assertNotWPError($repo->setEnabled($id,true));$review=ScopedProviderConnectionFixture::approve($id,$actors['author'],null,$capability);if(in_array($capability,['PROVIDER_ORDER_SUBMIT','PROVIDER_PRODUCTION_AUTHORIZE'],true))ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_connections','manage_digiforge_settings','manage_digiforge_pod','manage_digiforge_orders']);$context=Boundary::context('personalized_pod',$id,$capability);self::assertNotWPError($context);return [$id,$context,$review,$old];
 }
 private function simulated(callable $work):mixed {
  $values=['activation_authorized'=>true,'automation_armed'=>true,'stop_all'=>false,'printify'=>true,'printify_activation_authorized'=>true,'order_automation'=>true,'order_automation_activation_authorized'=>true];$table=Tables::settings();
  $query=static function(string $sql)use($values,$table):string{foreach($values as $key=>$value)if(str_contains($sql,'SELECT setting_value, setting_type FROM '.$table)&&str_contains($sql,"setting_key = '".$key."'"))return "SELECT '".($value?'true':'false')."' AS setting_value, '".DigiForge\Core\Config::setting_type($key)."' AS setting_type";return $sql;};
  $http=static fn($pre)=>$pre!==false?$pre:new WP_Error('offline_http_forbidden','Real HTTP forbidden');add_filter('query',$query);add_filter('pre_http_request',$http,PHP_INT_MAX);
  try{return $work();}finally{remove_filter('query',$query);remove_filter('pre_http_request',$http,PHP_INT_MAX);}
 }
 private function revoke(int $id,array $review):void {
  $actor=get_current_user_id();wp_set_current_user($review['reviewer']);$a=$review['approved'];try{self::assertNotWPError((new DigiForge\POD\ScopedProviderConnection())->decide('personalized_pod',$id,$review['input']['capability'],$a['revision'],$a['proposal_hash'],'REVOKED','cross-revoke-'.wp_generate_uuid4()));}finally{wp_set_current_user($actor);}
 }
 private function request(string $action='PROVIDER_ORDER_SUBMIT'):array {
  [$id,$context,$review,$old]=$this->fixture($action);global $wpdb;$hash=hash('sha256',wp_generate_uuid4());$nonce=hash('sha256','nonce-'.$hash);$now=gmdate('Y-m-d H:i:s');
  $ownership=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d',$old['provider_mapping_id']),ARRAY_A);self::assertIsArray($ownership);
  self::assertSame(1,$wpdb->update(Tables::pod_mappings(),['state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now],['id'=>$old['provider_mapping_id']]));
  self::assertSame(1,$wpdb->insert(Tables::pod_authorization_packages(),['order_id'=>1,'render_evidence_id'=>1,'provider_mapping_id'=>$old['provider_mapping_id'],'ownership_mapping_id'=>$ownership['id'],'readiness_hash'=>$hash,'package_hash'=>$hash,'state'=>'APPROVED_PACKAGE','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now]));$package=(int)$wpdb->insert_id;
  $payload=['integration_id'=>$id,'shop_id'=>(int)$context['external_shop_reference'],'connection_scope'=>$context,'order'=>['external_id'=>'synthetic-order','line_items'=>[['product_id'=>'synthetic']],'address_to'=>['country'=>'US']],'order_id'=>'synthetic-provider-order'];
  $permit=['state'=>'ADAPTER_CALL_PERMITTED','action'=>$action,'nonce_consumed'=>true,'external_execution_performed'=>false,'authorization_hash'=>$hash,'evidence_hash'=>$hash];
  $request=DigiForge\POD\PrintifyMutationRequest::prepare($permit,$payload);self::assertNotWPError($request);$permit['request_fingerprint']=$request['request_fingerprint'];
  $rawNonce=bin2hex(random_bytes(24));$issued=$this->simulated(fn()=>DigiForge\POD\ExecutionAuthorization::issue(['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','publishing_enabled'=>false,'order_execution_enabled'=>false,'evidence_hash'=>$hash],$action,get_current_user_id(),$rawNonce,300,$request['request_fingerprint']));self::assertNotWPError($issued);
  $permit['authorization']=$issued['authorization'];$permit['authorization_hash']=$issued['authorization_hash'];$nonce=hash('sha256',$rawNonce);$authorizationHash=$issued['authorization_hash'];
  self::assertSame(1,$wpdb->insert(Tables::pod_execution_nonces(),['nonce_hash'=>$nonce,'authorization_hash'=>$authorizationHash,'consumed_by'=>get_current_user_id(),'consumed_at'=>$now]));
  self::assertSame(1,$wpdb->insert(Tables::pod_authorization_bindings(),['package_id'=>$package,'package_hash'=>$hash,'authorization_hash'=>$authorizationHash,'nonce_hash'=>$nonce,'bound_by'=>get_current_user_id(),'bound_at'=>$now]));
  return [$permit,$payload,$review];
 }
 public function testMissingContextDeniesBeforeCredentialCiphertextRead():void {
  [$id]=$this->fixture();$reads=0;$calls=0;$observe=static function(string $q)use(&$reads):string{if(str_contains($q,'SELECT ciphertext'))++$reads;return $q;};add_filter('query',$observe);
  try{$out=$this->simulated(fn()=>(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,static function()use(&$calls){++$calls;}));self::assertWPError($out);}finally{remove_filter('query',$observe);}self::assertSame(0,$reads);self::assertSame(0,$calls);
 }
 public function testExactContextAllowsSyntheticCallbackButNeverOverridesLocks():void {
  [$id,$scope]=$this->fixture();$getter=new DigiForge\POD\PrintifyScopedCredentialRetriever();self::assertWPError($getter->consume($id,static fn()=>['safe'=>true],$scope));
  self::assertSame(['safe'=>true],$this->simulated(fn()=>$getter->consume($id,static fn()=>['safe'=>true],$scope)));self::assertTrue(DigiForge\Core\Settings::safety_locked());
 }
 /** @dataProvider mismatches */
 public function testEveryTupleComponentIsExact(string $field):void {
  [$id,$scope]=$this->fixture();$scope[$field]=is_int($scope[$field])?$scope[$field]+1:'foreign';self::assertWPError(Boundary::authorize($id,'PROVIDER_ORDER_SUBMIT',$scope));
 }
 public static function mismatches():array{return array_map(static fn($f)=>[$f],['installation_sha256','environment','business_id','store_id','program_id','shop_key','provider','external_account_reference','external_shop_reference','integration_id','capability','binding_hash']);}
 public function testRevocationDuringCredentialLookupDeniesCallback():void {
  [$id,$scope,$review]=$this->fixture();$changed=false;$calls=0;$query=function(string $q)use(&$changed,$id,$review):string{if(!$changed&&str_contains($q,'SELECT ciphertext')){$changed=true;$this->revoke($id,$review);}return $q;};add_filter('query',$query);
  try{self::assertWPError($this->simulated(fn()=>(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,static function()use(&$calls){++$calls;},$scope)));}finally{remove_filter('query',$query);}self::assertTrue($changed);self::assertSame(0,$calls);
 }
 public function testReturnedAndThrownCredentialMaterialIsDiscarded():void {
  [$id,$scope]=$this->fixture();foreach([static fn($s)=>$s,static fn($s)=>new WP_Error('private',$s),static fn($s)=>new WP_Error($s,'private'),static fn($s)=>(object)['value'=>$s],static function($s){throw new RuntimeException($s);}]as $callback){$out=$this->simulated(fn()=>(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,$callback,$scope));self::assertWPError($out);self::assertStringNotContainsString('synthetic-cross-transport-material',wp_json_encode($out));}
 }
 /** @dataProvider actions */
 public function testOneDispatchAndDeletedReceiptCannotReplay(string $action):void {
  [$permit,$payload]=$this->request($action);$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return ['response'=>['code'=>200],'body'=>'{"id":"synthetic-result"}'];});
  // End only the disposable WP test transaction: claims must be durable before a mock dispatch.
  global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$out=$this->simulated(fn()=>$transport->execute($permit,$payload));self::assertIsArray($out);self::assertSame('SUCCEEDED',$out['status']);self::assertSame(1,$calls);
  self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));$wpdb->delete($wpdb->options,['option_name'=>'df_printify_dispatch_'.$permit['authorization_hash']]);self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));self::assertSame(1,$calls);
 }
 public static function actions():array{return [['PROVIDER_ORDER_SUBMIT'],['PROVIDER_PRODUCTION_AUTHORIZE']];}
 public function testPostDispatchRevocationIsUnknownAndCannotRetry():void {
  [$permit,$payload,$review]=$this->request();$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(function()use(&$calls,$payload,$review){++$calls;$this->revoke($payload['integration_id'],$review);return ['response'=>['code'=>200],'body'=>'{"id":"synthetic-result"}'];});global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  $out=$this->simulated(fn()=>$transport->execute($permit,$payload));self::assertWPError($out);self::assertTrue($out->get_error_data()['network_request_attempted']);self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));self::assertSame(1,$calls);
 }
 public function testChangedScopeInvalidatesApprovedRequestFingerprint():void {
  [$permit,$payload]=$this->request();$payload['connection_scope']['business_id']++;$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return [];});self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));self::assertSame(0,$calls);
 }

 public function testConcurrentClaimAndForgedConsumedFlagCannotDispatch():void {
  [$permit,$payload]=$this->request();global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock='df-printify-dispatch-'.substr($permit['authorization_hash'],0,42);self::assertSame('1',(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock)));$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return [];});
  try{self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));}finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock));$other->close();}
  $wpdb->delete(Tables::pod_execution_nonces(),['authorization_hash'=>$permit['authorization_hash']]);self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));self::assertSame(0,$calls);
 }
 public function testOrderRoleRevocationAndForeignExternalShopDenyBeforeDispatch():void {
  [$permit,$payload]=$this->request();$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return [];});
  $payload['shop_id']++;$prepared=DigiForge\POD\PrintifyMutationRequest::prepare($permit,$payload);$permit['request_fingerprint']=$prepared['request_fingerprint'];self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));
  ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_connections','manage_digiforge_settings','manage_digiforge_pod']);$user=wp_get_current_user();$user->add_cap('manage_digiforge_orders',false);self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));self::assertSame(0,$calls);
 }
 public function testReconciliationRejectsLegacyIdentityAndRechecksEveryPage():void {
  [$id,$scope,$review]=$this->fixture();$calls=0;$lookup=new DigiForge\POD\PrintifyReconciliationLookup(function()use(&$calls,$id,$review){++$calls;$this->revoke($id,$review);return ['response'=>['code'=>200],'body'=>'{"data":[],"last_page":3}'];});
  $outcome=['state'=>'EXECUTION_UNKNOWN','unknown'=>['action'=>'PROVIDER_ORDER_SUBMIT','request_fingerprint'=>str_repeat('a',64),'reconciliation_identity'=>wp_json_encode(['integration_id'=>$id,'shop_id'=>(int)$scope['external_shop_reference'],'external_reference'=>'synthetic-order'])]];
  self::assertWPError($this->simulated(fn()=>$lookup->lookup($outcome,$id)));self::assertSame(0,$calls);
  $outcome['unknown']['reconciliation_identity']=wp_json_encode(['integration_id'=>$id,'connection_scope'=>$scope,'shop_id'=>(int)$scope['external_shop_reference'],'external_reference'=>'synthetic-order']);self::assertWPError($this->simulated(fn()=>$lookup->lookup($outcome,$id)));self::assertSame(1,$calls);
 }
 public function testLegacyCatalogRetryCannotIgnoreBindingRevocation():void {
  [$id,$scope,$review]=$this->fixture('printify.catalog.read');$calls=0;$client=new DigiForge\POD\PrintifyCatalogClient(static function()use(&$calls){++$calls;return ['response'=>['code'=>429],'headers'=>['retry-after'=>'0'],'body'=>'{}'];},function()use($id,$review){$this->revoke($id,$review);},static fn()=>true);
  self::assertWPError($this->simulated(fn()=>(new DigiForge\POD\PrintifyCatalogSyncService(static fn()=>$client))->syncBlueprint($id,101,$scope)));self::assertSame(1,$calls);
 }
 public function testLocksChangingDuringCredentialLookupDenyCallback():void {
  [$id,$scope]=$this->fixture();$calls=0;$changed=false;
  $filter=static function(string $q)use(&$changed):string{if(str_contains($q,'SELECT ciphertext'))$changed=true;if($changed&&str_contains($q,"setting_key = 'stop_all'"))return "SELECT 'true' AS setting_value,'bool' AS setting_type";return $q;};
  $result=$this->simulated(function()use($id,$scope,&$calls,$filter){add_filter('query',$filter,5);try{return (new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,static function()use(&$calls){++$calls;},$scope);}finally{remove_filter('query',$filter,5);}});self::assertWPError($result);self::assertSame(0,$calls);
 }

 public function testCiphertextDriftCannotBorrowUnchangedMetadata():void {
  [$id,$scope]=$this->fixture();global $wpdb;$cipher=DigiForge\Integrations\CredentialVault::encrypt('different-synthetic-plaintext',DigiForge\Integrations\Repository::secretContext($id,'personal_access_token'));$wpdb->update(Tables::integration_secrets(),['ciphertext'=>$cipher],['integration_id'=>$id,'secret_name'=>'personal_access_token']);$calls=0;
  $result=$this->simulated(fn()=>(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,static function()use(&$calls){++$calls;},$scope));self::assertWPError($result);self::assertSame('printify_connection_material_changed',$result->get_error_code());self::assertSame(0,$calls);
 }
 public function testConnectionReadRejectsForeignShopResponseWithoutReadiness():void {
  [$id,$scope]=$this->fixture('printify.connection.read');$http=static fn()=>['response'=>['code'=>200],'body'=>'[{"id":111,"title":"foreign","sales_channel":"etsy"}]'];
  $out=$this->simulated(function()use($id,$scope,$http){add_filter('pre_http_request',$http,1);try{return (new DigiForge\Integrations\ConnectionTester())->test($id,$scope);}finally{remove_filter('pre_http_request',$http,1);}});self::assertWPError($out);self::assertSame('printify_foreign_shop_response',$out->get_error_code());$i=(new DigiForge\Integrations\Repository())->find($id);self::assertArrayNotHasKey('_connection_test',$i['config']);
 }

 public function testCredentialLeakInSecondaryErrorHistoryIsDiscarded():void {
  [$id,$scope]=$this->fixture();$out=$this->simulated(fn()=>(new DigiForge\POD\PrintifyScopedCredentialRetriever())->consume($id,static function($secret){$error=new WP_Error('safe','Safe failure',['safe'=>true]);$error->add('secondary','Another failure',['credential'=>$secret]);$error->add_data(['safe'=>true],'secondary');return $error;},$scope));self::assertWPError($out);self::assertSame('printify_credential_protected_result',$out->get_error_code());
 }
 public function testExpiredConsumedAuthorizationCannotDispatch():void {
  [$permit,$payload]=$this->request();global $wpdb;$old=$permit['authorization_hash'];$permit['authorization']['issued_at']=time()-301;$permit['authorization']['expires_at']=time()-1;$canonical=$permit['authorization'];ksort($canonical);$permit['authorization_hash']=hash('sha256',wp_json_encode($canonical));
  foreach([Tables::pod_execution_nonces(),Tables::pod_authorization_bindings()]as $table)self::assertSame(1,$wpdb->update($table,['authorization_hash'=>$permit['authorization_hash']],['authorization_hash'=>$old]));$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return [];});$out=$this->simulated(fn()=>$transport->execute($permit,$payload));self::assertWPError($out);self::assertSame('digiforge_execution_expired',$out->get_error_code());self::assertSame(0,$calls);
 }
 public function testDispatchAuditFailureRollsBackAndNeverSends():void {
  [$permit,$payload]=$this->request();global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$calls=0;$transport=new DigiForge\POD\PrintifyControlledTransport(static function()use(&$calls){++$calls;return [];});$fault=static fn(string $q):string=>str_starts_with($q,'INSERT INTO `'.Tables::audit_log().'`')&&str_contains($q,'printify_dispatch_claimed')?'SELECT FROM /* synthetic audit failure */':$q;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
  try{self::assertWPError($this->simulated(fn()=>$transport->execute($permit,$payload)));}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}self::assertSame(0,$calls);self::assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'df_printify_dispatch_'.$permit['authorization_hash'])));
 }
}
