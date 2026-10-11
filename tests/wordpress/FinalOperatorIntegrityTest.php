<?php
declare(strict_types=1);
/** Blueprint p232/p234/p236: local evidence failures and exact order identity, no external execution. */
final class FinalOperatorIntegrityTest extends WP_UnitTestCase {
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public static function operatorActions():array {return array_map(static fn(string $m):array=>[$m],['review','develop','saveAiSecret','saveAiPolicy','listingReview','podPackageReview','podRenderReview','master500Acceptance','recoveryPlan','recoveryAccept','personalizationReview','ownershipReview']);}
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
