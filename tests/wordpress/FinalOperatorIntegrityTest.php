<?php
declare(strict_types=1);
/** Blueprint p232/p234/p236: local evidence failures and exact order identity, no external execution. */
final class FinalOperatorIntegrityTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public static function operatorActions():array {return array_map(static fn(string $m):array=>[$m],['review','develop','saveAiSecret','saveAiPolicy','listingReview','podPackageReview','podRenderReview','master500Acceptance','recoveryPlan','recoveryAccept','personalizationReview','ownershipReview','saveNotificationPreferences','requestReplacementReview','decideReplacementReview']);}
 /** @dataProvider operatorActions */
 public function testOperatorHandlersRejectUnauthorizedOrInvalidNonceBeforeWrites(string $method):void {
  global $wpdb;$mutations=[];$query=static function(string $sql)use(&$mutations):string{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$sql)&&str_contains($sql,'digiforge_'))$mutations[]=$sql;return $sql;};
  $post=$_POST;$request=$_REQUEST;$user=get_current_user_id();$die=static fn()=>static function():never{throw new RuntimeException('operator-denied');};add_filter('wp_die_handler',$die);add_filter('query',$query);
  try{foreach([0,$user] as $actor){wp_set_current_user($actor);$_POST=$_REQUEST=['_wpnonce'=>'invalid-offline-nonce'];$denied=false;try{(new DigiForge\Portal\Portal())->$method();}catch(RuntimeException $e){self::assertSame('operator-denied',$e->getMessage());$denied=true;}self::assertTrue($denied);}}finally{remove_filter('wp_die_handler',$die);remove_filter('query',$query);wp_set_current_user($user);$_POST=$post;$_REQUEST=$request;}
  self::assertSame([],$mutations);
 }
 public function testNativePortalResearchReviewRecordsEvidenceWithoutExternalAuthority():void {
  $repo=new DigiForge\Research\Repository();$candidate=$repo->createCandidate(['shop_key'=>'digital','title'=>'Offline portal review '.wp_generate_uuid4()]);self::assertNotWPError($candidate);
  $portal=new DigiForge\Portal\Portal();$portal->register();add_action('digiforge_log',[DigiForge\Security\Logger::class,'write'],10,4);
  $action=(new ReflectionClass($portal))->getConstant('REVIEW_ACTION');$post=$_POST;$request=$_REQUEST;$redirect='';$capture=static function(string $url)use(&$redirect):never{$redirect=$url;throw new RuntimeException('operator-redirect');};add_filter('wp_redirect',$capture);
  try{$_POST=$_REQUEST=['candidate_id'=>$candidate['id'],'decision'=>'APPROVED','notes'=>'Local fixture review, no market certification.','_wpnonce'=>wp_create_nonce($action)];try{do_action('admin_post_'.$action);self::fail('Redirect must complete local review.');}catch(RuntimeException $e){self::assertSame('operator-redirect',$e->getMessage());}}finally{remove_filter('wp_redirect',$capture);$_POST=$post;$_REQUEST=$request;}
  self::assertStringContainsString('df_view=approvals',$redirect);global $wpdb;self::assertSame('APPROVED',$wpdb->get_var($wpdb->prepare('SELECT review_status FROM '.DigiForge\Database\Tables::research_candidates().' WHERE id=%d',$candidate['id'])));self::assertGreaterThan(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DigiForge\Database\Tables::audit_log().' WHERE object_type=%s AND object_id=%s','research_candidate',(string)$candidate['id'])));
  self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
 }
 public function testSelectedShopFiltersResearchEvidenceRowsAndCounts():void {
  $r=new DigiForge\Research\Repository();$marker='Scoped portal '.wp_generate_uuid4();
  foreach(['digital','personalized_pod'] as $shop){
   $source=$r->createSource(['shop_key'=>$shop,'name'=>$marker.' source '.$shop]);self::assertNotWPError($source);
   $observation=$r->ingest(['source_id'=>$source['id'],'title'=>$marker.' observation '.$shop]);self::assertNotWPError($observation);
   $evidence=$r->addEvidence((int)$observation['id'],['value'=>$marker.' evidence '.$shop]);self::assertNotWPError($evidence);
   $candidate=$r->createCandidate(['shop_key'=>$shop,'title'=>$marker.' candidate '.$shop]);self::assertNotWPError($candidate);
   self::assertNotWPError($r->review((int)$candidate['id'],'APPROVED',$marker.' review '.$shop));
  }
  foreach(['sources','observations','evidence','reviews'] as $entity){$table=DigiForge\Database\Tables::{'research_'.$entity}();$expected=$r->list($entity,1,50,'digital');self::assertNotWPError($expected);
   $html=$this->evidencePanel($table,['df_shop'=>'digital']);self::assertStringContainsString($marker.' '.rtrim($entity,'s').' digital',$html);self::assertStringNotContainsString($marker.' '.rtrim($entity,'s').' personalized_pod',$html);self::assertStringContainsString((string)$expected['pagination']['total_items'].' records',$html);
  }
 }
 public function testSelectedShopFiltersOrdersAndCannotFocusAnotherShopsReview():void {
  global $wpdb;$r=new DigiForge\Orders\Repository();$marker='Scoped-order-'.wp_generate_uuid4();$reviews=[];$children=[];$ordersByShop=[];
  foreach(['digital','personalized_pod'] as $shop){$order=$r->createOrder(['shop_reference'=>$shop,'external_order_reference'=>$marker.'-'.$shop,'environment'=>'sandbox']);self::assertNotWPError($order);$ordersByShop[$shop]=(int)$order['id'];$now=current_time('mysql',true);
   self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_readiness_reviews(),['order_id'=>$order['id'],'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker.$shop),'created_at'=>$now,'updated_at'=>$now]));$reviews[$shop]=(int)$wpdb->insert_id;
   // Synthetic read-model fixtures prove parent-bound scope, not product/paid-order certification.
   $base=['environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now];
   self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::order_line_items(),$base+['order_id'=>$order['id'],'product_version_id'=>1]));$lineId=(int)$wpdb->insert_id;
   self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::personalization_submissions(),$base+['order_line_item_id'=>$lineId,'personalization_schema_id'=>1,'canonical_payload'=>'{}','payload_hash'=>hash('sha256',$marker.'personalization'.$shop)]));$children[$shop]['personalization_submissions']=(int)$wpdb->insert_id;
   $children[$shop]['order_line_items']=$lineId;
   self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_plans(),$base+['order_id'=>$order['id'],'plan_version'=>'scope-fixture','canonical_payload'=>'{}','payload_hash'=>hash('sha256',$marker.'plan'.$shop),'readiness'=>'{}','readiness_hash'=>hash('sha256',$marker.'plan-readiness'.$shop)]));$children[$shop]['fulfillment_plans']=(int)$wpdb->insert_id;
   self::assertSame(1,$wpdb->insert(DigiForge\Database\Tables::fulfillment_intents(),$base+['order_id'=>$order['id'],'intent_type'=>'OFFLINE_SCOPE_FIXTURE']));$children[$shop]['fulfillment_intents']=(int)$wpdb->insert_id;

  }
  $get=$_GET;try{$_GET=['df_view'=>'orders','df_shop'=>'digital'];$rendered=(new DigiForge\Portal\Portal())->render();self::assertStringContainsString('<tr><td>#'.$ordersByShop['digital'].'</td><td>digital</td>',$rendered);self::assertStringNotContainsString('<tr><td>#'.$ordersByShop['personalized_pod'].'</td><td>personalized_pod</td>',$rendered);}finally{$_GET=$get;}
  $html=$this->evidencePanel(DigiForge\Database\Tables::orders(),['df_shop'=>'digital']);self::assertStringContainsString($marker.'-digital',$html);self::assertStringNotContainsString($marker.'-personalized_pod',$html);
  $html=$this->evidencePanel(DigiForge\Database\Tables::fulfillment_readiness_reviews(),['df_shop'=>'digital','df_focus_type'=>'fulfillment_review','df_focus_id'=>$reviews['personalized_pod']]);self::assertStringContainsString('Requested evidence record is unavailable',$html);self::assertStringNotContainsString('id="df-evidence-fulfillment_review-'.$reviews['personalized_pod'].'"',$html);
  foreach($children['digital'] as $entity=>$id){$html=$this->evidencePanel(DigiForge\Database\Tables::{$entity}(),['df_shop'=>'digital']);self::assertStringContainsString('<tr><td>'.$id.'</td>',$html);self::assertStringNotContainsString('<tr><td>'.$children['personalized_pod'][$entity].'</td>',$html);}
  $all=$this->evidencePanel(DigiForge\Database\Tables::orders(),['df_shop'=>'all']);self::assertStringContainsString($marker.'-digital',$all);self::assertStringContainsString($marker.'-personalized_pod',$all);
 }
 public function testQueueDiagnosticFailureCannotClaimAvailableRecovery():void {
  global $wpdb;$now=current_time('mysql',true);$jobs=DigiForge\Database\Tables::jobs();$ids=[];
  foreach(['BLOCKED','DEAD_LETTER','RUNNING'] as $state){self::assertSame(1,$wpdb->insert($jobs,['job_type'=>'offline_recovery_fixture','state'=>$state,'last_error'=>'Offline rate limit 429 fixture','lease_expires_at'=>'2000-01-01 00:00:00','created_at'=>$now,'updated_at'=>$now]));$ids[$state]=(int)$wpdb->insert_id;}
  $model=new DigiForge\Queue\OperatorQueueReadModel();$available=$model->recoveryEvidence();self::assertSame('AVAILABLE',$available['query_state']);self::assertTrue($available['correlation']['requires_operator_review']);self::assertContains($ids['BLOCKED'],array_map('intval',array_column($available['attention'],'id')));
  foreach(['ORPHAN_EVIDENCE_REQUIRES_OPERATOR_REVIEW','RATE_LIMIT_EVIDENCE_REQUIRES_BACKOFF_REVIEW','DEAD_LETTER_REQUIRES_OPERATOR_REVIEW'] as $classification)self::assertContains($classification,$available['classification']['classifications']);
  $filter=static fn(string $sql):string=>str_starts_with($sql,'SELECT COUNT(*) FROM '.$jobs)?str_replace($jobs,'digiforge_missing_queue_count_fixture',$sql):$sql;$errors=$wpdb->suppress_errors(true);add_filter('query',$filter);
  try{$failed=$model->recoveryEvidence();}finally{remove_filter('query',$filter);$wpdb->suppress_errors($errors);}
  self::assertSame('UNAVAILABLE',$failed['query_state']);self::assertTrue($failed['correlation']['requires_operator_review']);self::assertContains('QUEUE_EVIDENCE_UNAVAILABLE_REQUIRES_REVIEW',$failed['classification']['classifications']);
  foreach(['automatic_recovery_permitted','replay_permitted','retry_permitted','external_execution_authorized'] as $key)self::assertFalse($failed[$key]);self::assertTrue($failed['read_only']);
 }
 private function evidencePanel(string $table,array $query):string {
  $get=$_GET;try{$_GET=$query;ob_start();(new ReflectionMethod(DigiForge\Portal\Portal::class,'panelTable'))->invoke(new DigiForge\Portal\Portal(),'Local evidence',$table);return (string)ob_get_clean();}finally{$_GET=$get;}
 }
 public function testPortalTableFailureCannotBecomeEmptyEvidence():void {
  global $wpdb;$table=DigiForge\Database\Tables::research_sources();$filter=static fn(string $sql):string=>str_contains($sql,'FROM '.$table)?str_replace($table,'digiforge_missing_operator_source',$sql):$sql;
  $method=(new ReflectionClass(DigiForge\Portal\Portal::class))->getMethod('panelTable');$method->setAccessible(true);$before=$wpdb->suppress_errors(true);add_filter('query',$filter);
  try{ob_start();$method->invoke(new DigiForge\Portal\Portal(),'Sources',$table);$html=ob_get_clean();}finally{remove_filter('query',$filter);$wpdb->suppress_errors($before);}
  self::assertStringContainsString('UNAVAILABLE',$html);self::assertStringContainsString('Evidence unavailable',$html);self::assertStringNotContainsString('No records found.',$html);
 }
 public function testPortalSuccessfulEmptyTableRemainsDistinguishable():void {
  global $wpdb;$table=DigiForge\Database\Tables::research_sources();
  $wpdb->query('DELETE FROM '.$table);$method=(new ReflectionClass(DigiForge\Portal\Portal::class))->getMethod('panelTable');$method->setAccessible(true);
  ob_start();$method->invoke(new DigiForge\Portal\Portal(),'Sources',$table);$html=ob_get_clean();self::assertStringContainsString('0 records',$html);self::assertStringContainsString('No records found.',$html);self::assertStringNotContainsString('Evidence unavailable',$html);
 }
 public function testOrderLookupUsesExactShopAndExternalIdentity():void {
  $r=new DigiForge\Orders\Repository();$ref='Offline-Exact-'.wp_generate_uuid4();$row=$r->createOrder(['shop_reference'=>'shop-exact','external_order_reference'=>$ref,'environment'=>'production']);self::assertNotWPError($row);
  self::assertSame((int)$row['id'],(int)$r->findByExternalReference($ref,'shop-exact')['id']);
  foreach(['SHOP-EXACT','shop-exact '] as $shop)self::assertNull($r->findByExternalReference($ref,$shop));
  foreach([strtolower($ref),$ref.' '] as $other)self::assertNull($r->findByExternalReference($other,'shop-exact'));
 }
 public function testProductionWebhookLookupCannotReuseSandboxOrder():void {
  $r=new DigiForge\Orders\Repository();$ref='offline-environment-'.wp_generate_uuid4();$sandbox=$r->createOrder(['shop_reference'=>'offline-shop','external_order_reference'=>$ref,'environment'=>'sandbox']);self::assertNotWPError($sandbox);self::assertNull($r->findByExternalReference($ref,'offline-shop'));
  $production=$r->createOrder(['shop_reference'=>'offline-shop','external_order_reference'=>$ref,'environment'=>'production']);self::assertNotWPError($production);self::assertSame((int)$production['id'],(int)$r->findByExternalReference($ref,'offline-shop')['id']);
 }
 public function testAmbiguousProductionOrderIdentityRequiresReconciliation():void {
  $r=new DigiForge\Orders\Repository();$ref='offline-ambiguous-'.wp_generate_uuid4();foreach([1,2] as $n)self::assertNotWPError($r->createOrder(['shop_reference'=>'offline-shop','external_order_reference'=>$ref,'environment'=>'production']));$result=$r->findByExternalReference($ref,'offline-shop');self::assertWPError($result);self::assertSame('order_reference_ambiguous',$result->get_error_code());
 }
}
