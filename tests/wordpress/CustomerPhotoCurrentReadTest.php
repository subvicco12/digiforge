<?php
declare(strict_types=1);
require_once __DIR__.'/RenderArtifactCertificationTest.php';
require_once __DIR__.'/fixtures/PhotoWorkflowFixtureIsolation.php';
use DigiForge\Database\Tables;
use DigiForge\POD\{CustomerEditorRepository,CustomerPhotoSelection,RasterArtworkCodec};

/** Real connections and public producer operations; no fabricated photo heads or resolver stand-ins. */
final class CustomerPhotoCurrentReadTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation;
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->capturePhotoFixtureState();$this->isolatePhotoFixtureIdentity();global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));}
 private function fixture(bool $select=true):array {
  $f=new RenderArtifactCertificationTest();$input=(new ReflectionMethod($f,'deterministicFixture'))->invoke($f,'SINGLE_PHOTO',[['position'=>'front','decoration_method'=>'dtg','width_px'=>640,'height_px'=>480,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>20,'y'=>20,'width'=>600,'height'=>440],'seam_exclusions'=>[],'min_image_dpi'=>72,'bleed'=>['trim_box'=>['x'=>10,'y'=>10,'width'=>620,'height'=>460],'margins_px'=>['left'=>10,'right'=>10,'top'=>10,'bottom'=>10]]]]]);
  $args=[$input,[['key'=>'photo','type'=>'image_reference','required'=>true],['key'=>'label','type'=>'text']],['photo'=>'digiforge/product-factory/source.png']];
  (new ReflectionMethod($f,'familyPayload'))->invokeArgs($f,[&$args[0],$args[1],$args[2]]);$input=$args[0];
  $actor=get_current_user_id();ShopAccessFixture::authorizeActor($actor,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_orders']);
  $customer=self::factory()->user->create(['role'=>'subscriber']);$grant=(new ReflectionMethod($f,'editorGrant'))->invoke($f,$input,$customer);
  $request=['request_key'=>'current-read-first','expected_head'=>null,'mode'=>'PHOTO','image_base64'=>base64_encode(RasterArtworkCodec::encode(256,256,str_repeat("\x40\x80\xa0\xff",65536))),'image_field'=>'photo','fields'=>[],'selection'=>['x'=>0,'y'=>0,'width'=>256,'height'=>256],'rights_confirmed'=>true,'processing_consent'=>true];
  $out=['order'=>$input['order_id'],'line'=>$grant['line_item_id'],'session'=>$grant['session_key'],'grant'=>$grant,'customer'=>$customer,'actor'=>$actor,'request'=>$request,'input'=>$input];
  if($select)$out+=$this->select($out,$request,0);return $out;
 }
 private function checked($v):array {self::assertNotWPError($v);self::assertIsArray($v);return $v;}
 private function select(array $f,array $request,int $prior):array {
  wp_set_current_user($f['customer']);$version=$this->checked((new CustomerEditorRepository())->upload($f['session'],$request));$photo=new CustomerPhotoSelection();$selection=$this->checked($photo->submit($f['session'],$version['sha256'],$prior));wp_set_current_user($f['actor']);$this->checked($photo->review($f['session'],$selection['sha256'],$prior+1,'APPROVED','Synthetic independent current-input review'));return ['version'=>$version,'selection'=>$selection];
 }
 private function staleSnapshot(callable $body):void {
  global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$prior=(string)$primary->get_var('SELECT @@session.tx_isolation');$modes=['READ-COMMITTED'=>'READ COMMITTED','REPEATABLE-READ'=>'REPEATABLE READ','READ-UNCOMMITTED'=>'READ UNCOMMITTED','SERIALIZABLE'=>'SERIALIZABLE'];self::assertArrayHasKey($prior,$modes);
  try {self::assertNotFalse($primary->query('COMMIT'));self::assertNotFalse($primary->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'));self::assertNotFalse($primary->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));$body($primary,$other);}
  finally {$wpdb=$primary;$primary->query('ROLLBACK');$primary->query('SET SESSION TRANSACTION ISOLATION LEVEL '.$modes[$prior]);$other->query('ROLLBACK');$other->close();}
 }
 public function testPublicReviewProofBindsIndependentReviewerAfterIdenticalPayloadReuse():void {
  $f=$this->fixture();$photo=new CustomerPhotoSelection();$first=$this->checked($photo->forOrder('personalized_pod',$f['order'],$f['line']));
  self::assertArrayNotHasKey('review_provenance',$this->checked($photo->current($f['session'],$f['selection']['sha256'],1,true)),'Only strict fenced operator consumers may obtain the public proof.');self::assertArrayHasKey('review_provenance',$first);$proof=$first['review_provenance'];self::assertSame('shop2-photo-review-proof-v1',$proof['version']);self::assertSame($f['actor'],$proof['reviewer_user_id']);
  $request=$f['request'];$request['request_key']='independent-review-identical-payload';$request['expected_head']=$f['version']['sha256'];wp_set_current_user($f['customer']);$version=$this->checked((new CustomerEditorRepository())->upload($f['session'],$request));$selected=$this->checked($photo->submit($f['session'],$version['sha256'],1));self::assertArrayNotHasKey('review_provenance',$selected);self::assertSame($f['selection']['submission_id'],$selected['submission_id']);
  $reviewer=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($reviewer,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_orders']);global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));wp_set_current_user($reviewer);$reviewed=$this->checked($photo->review($f['session'],$selected['sha256'],2,'APPROVED','Private synthetic review note'));self::assertArrayNotHasKey('review_provenance',$reviewed);wp_set_current_user($f['actor']);
  $current=$this->checked($photo->forOrder('personalized_pod',$f['order'],$f['line'],2));$proof=$current['review_provenance'];self::assertSame(['version','selection_sha256','selection_revision','reviewer_user_id','review_sha256','decision','reviewed_at'],array_keys($proof));self::assertSame($selected['sha256'],$proof['selection_sha256']);self::assertSame(2,$proof['selection_revision']);self::assertSame($reviewer,$proof['reviewer_user_id']);self::assertSame('APPROVED',$proof['decision']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$proof['review_sha256']);self::assertNotSame($first['review_provenance']['review_sha256'],$proof['review_sha256']);self::assertNotFalse(strtotime($proof['reviewed_at']));self::assertSame($selected['sha256'],$current['sha256']);
  self::assertSame((string)$f['actor'],(string)$wpdb->get_var($wpdb->prepare('SELECT reviewed_by FROM '.Tables::personalization_submissions().' WHERE id=%d',$selected['submission_id'])),'Canonical payload reviewer must not substitute for the independent selection reviewer.');
  $u=get_user_by('id',$reviewer);$u->add_cap('manage_digiforge_orders',false);self::assertWPError($photo->forOrder('personalized_pod',$f['order'],$f['line'],2),'Revoked independent reviewer authority must deny the entire proof.');
 }
 public function testOperatorSessionTargetRequiresOnlyItsStoredShopAndCurrentOperator():void {
  $f=$this->fixture(false);$repo=new CustomerEditorRepository();self::assertTrue(method_exists($repo,'targetShopForSession'),'A stored-session operator scope contract is required.');$acl=new DigiForge\Security\ShopAccessRepository();
  foreach(['digital','standard_pod','jewelry']as $shop){$state=$this->checked($acl->read($shop));foreach($state['grants']as $id=>$grant){if($grant['actor_id']!==$f['actor']||$grant['revoked'])continue;wp_set_current_user($grant['grantor_id']);$current=$this->checked($acl->read($shop));$this->checked($acl->revoke($shop,$id,str_replace('-','_',wp_generate_uuid4()),$current['revision']));}}
  wp_set_current_user($f['actor']);self::assertSame(['personalized_pod'],DigiForge\Security\ShopAccess::allowedShops('manage_digiforge_pod'));self::assertSame('shop2-editor-session-scope-v1',CustomerEditorRepository::OPERATOR_SESSION_SCOPE_CONTRACT);self::assertSame('personalized_pod',$repo->targetShopForSession($f['session']));self::assertWPError($repo->targetShopForSession('unknown_session'));
  wp_set_current_user($f['customer']);self::assertWPError($repo->targetShopForSession($f['session']),'Buyer access must not become operator admission.');wp_set_current_user($f['actor']);$this->checked($repo->revoke($f['session'],$f['grant']['sha256']));self::assertWPError($repo->targetShopForSession($f['session']));
 }
 public function testPodOnlyOperatorCanUseOwnedPhotoRestRouteWithoutOtherShopAssignments():void {
  $f=$this->fixture();$acl=new DigiForge\Security\ShopAccessRepository();foreach(['digital','standard_pod','jewelry']as $shop){$state=$this->checked($acl->read($shop));foreach($state['grants']as $id=>$grant){if($grant['actor_id']!==$f['actor']||$grant['revoked'])continue;wp_set_current_user($grant['grantor_id']);$fresh=$this->checked($acl->read($shop));$this->checked($acl->revoke($shop,$id,str_replace('-','_',wp_generate_uuid4()),$fresh['revision']));}}wp_set_current_user($f['actor']);self::assertSame(['personalized_pod'],DigiForge\Security\ShopAccess::allowedShops('manage_digiforge_pod'));self::assertNotWPError((new CustomerPhotoSelection())->operatorView($f['session']));
  $request=new WP_REST_Request('POST','/digiforge/v1/customer-editor/'.$f['session'].'/intake');$request->set_header('Content-Type','application/json');$request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$request->set_body(wp_json_encode(['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod']));$response=rest_do_request($request);self::assertSame(200,$response->get_status(),'An admitted stored Shop2 session must not require assignments to all other shops.');self::assertSame($f['order'],$response->get_data()['order_id']);
  $request->set_body(wp_json_encode(['operation'=>'PHOTO_VIEW','shop_key'=>'digital']));self::assertSame(403,rest_do_request($request)->get_status());$request->set_body(wp_json_encode(['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod']));$request->set_header('X-WP-Nonce','invalid');self::assertSame(403,rest_do_request($request)->get_status());
 }
 public function testOperatorSessionTargetRejectsCallerSnapshotAndCommittedForeignOrderScope():void {
  $f=$this->fixture(false);$repo=new CustomerEditorRepository();self::assertTrue(method_exists($repo,'targetShopForSession'));global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$old=$primary->get_var($primary->prepare('SELECT shop_reference FROM '.Tables::orders().' WHERE id=%d',$f['order']));$probe='scope-target-caller-'.wp_generate_uuid4();
  try{self::assertSame('personalized_pod',$repo->targetShopForSession($f['session']));self::assertNotFalse($primary->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));self::assertSame(1,$primary->insert($primary->options,['option_name'=>$probe,'option_value'=>'uncommitted','autoload'=>'no']));self::assertSame(1,$other->update(Tables::orders(),['shop_reference'=>'digital'],['id'=>$f['order']]));self::assertWPError($repo->targetShopForSession($f['session']));self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$probe)));self::assertNotFalse($primary->query('ROLLBACK'));self::assertWPError($repo->targetShopForSession($f['session']),'A fresh read must reject the committed foreign shop.');}
  finally{$wpdb=$primary;$primary->query('ROLLBACK');self::assertNotFalse($other->update(Tables::orders(),['shop_reference'=>$old],['id'=>$f['order']]));$other->close();}self::assertNull($primary->get_var($primary->prepare('SELECT option_value FROM '.$primary->options.' WHERE option_name=%s',$probe)));
 }
 public function testOperatorSessionTargetRejectsChangedBoundAccessDocument():void {
  $f=$this->fixture(false);$repo=new CustomerEditorRepository();self::assertTrue(method_exists($repo,'targetShopForSession'));$path=DigiForge\ProductFactory\AssetStorage::absolutePath($f['grant']['proof_reference']);self::assertNotNull($path);$original=file_get_contents($path);
  try{file_put_contents($path,'{}');self::assertWPError($repo->targetShopForSession($f['session']));}finally{file_put_contents($path,$original);}self::assertSame('personalized_pod',$repo->targetShopForSession($f['session']));
 }
 public function testReplacementCannotReturnOldSelectionFromActualRepeatableReadSnapshot():void {
  $f=$this->fixture();$photo=new CustomerPhotoSelection();
  $this->staleSnapshot(function($primary,$other)use($f,$photo):void {
   global $wpdb;self::assertSame($f['selection']['sha256'],$primary->get_var($primary->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(option_value,'$.sha256')) FROM {$primary->options} WHERE option_name=%s",'df_photo_head_'.$f['line'])));
   $wpdb=$other;$request=$f['request'];$request['request_key']='actual-second-connection';$request['expected_head']=$f['version']['sha256'];$next=$this->select($f,$request,1);self::assertSame('0',(string)$other->get_var('SELECT @@session.in_transaction'));self::assertSame($f['selection']['submission_id'],$next['selection']['submission_id']);self::assertSame($next['selection']['sha256'],$this->checked($photo->forOrder('personalized_pod',$f['order'],$f['line']))['sha256']);
   $wpdb=$primary;$result=$photo->forOrder('personalized_pod',$f['order'],$f['line']);self::assertTrue(is_wp_error($result)||(is_array($result)&&$result['sha256']===$next['selection']['sha256']),'Old real RR must return current selection or explicit denial.');self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'),'Resolver must preserve caller transaction.');self::assertWPError($photo->forRender(['order_id'=>$f['order'],'provider_mapping_id'=>$f['selection']['snapshot']['provider_mapping_id'],'personalization_evidence_hash'=>$next['selection']['snapshot']['payload_sha256']]));
  });
 }
 public function testFirstManagedApprovalCannotRetainOldLegacyNull():void {
  $f=$this->fixture(false);$photo=new CustomerPhotoSelection();self::assertNull($photo->resolveForOrder('personalized_pod',$f['order'],$f['line']));
  $this->staleSnapshot(function($primary,$other)use($f,$photo):void {
   global $wpdb;self::assertSame('0',(string)$primary->get_var($primary->prepare("SELECT COUNT(*) FROM {$primary->options} WHERE option_name=%s",'df_photo_head_'.$f['line'])));
   $wpdb=$other;$next=$this->select($f,$f['request'],0);self::assertSame($next['selection']['sha256'],$this->checked($photo->forOrder('personalized_pod',$f['order'],$f['line']))['sha256']);
   $wpdb=$primary;$result=$photo->resolveForOrder('personalized_pod',$f['order'],$f['line']);self::assertTrue(is_wp_error($result)||(is_array($result)&&$result['sha256']===$next['selection']['sha256']),'First managed approval must invalidate old legacy NULL.');self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));
  });
 }
 public function testProducerFenceBlocksActualSecondConnectionEditorAndSelectionMutations():void {
  $f=$this->fixture();$photo=new CustomerPhotoSelection();$this->checked($photo->approvePhotoLayout($f['session'],$f['selection']['sha256'],1,$f['input']['template_key'],1,'Synthetic explicit geometry'));$render=$this->checked($photo->prepare($f['session'],$f['selection']['sha256'],1,$f['input']['template_key'],1));self::assertTrue(class_exists(\DigiForge\POD\CustomerPhotoProducerFence::class),'A pre-transaction producer fence is required.');
  global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);
  $fence=\DigiForge\POD\CustomerPhotoProducerFence::acquireRead();self::assertNotWPError($fence);
  try {
   self::assertSame($f['selection']['sha256'],$this->checked((new CustomerPhotoSelection())->forOrder('personalized_pod',$f['order'],$f['line']))['sha256']);
   $wpdb=$other;wp_set_current_user($f['customer']);$editor=new CustomerEditorRepository();
   $request=$f['request'];$request['request_key']='blocked-by-producer-fence';$request['expected_head']=$f['version']['sha256'];
   self::assertWPError($editor->upload($f['session'],$request));self::assertWPError($editor->saveFields($f['session'],$f['version']['sha256'],['label'=>'New text']));self::assertWPError($editor->reorderImages($f['session'],$f['version']['sha256'],['photo']));
   wp_set_current_user($f['actor']);self::assertWPError($editor->revoke($f['session'],$f['grant']['sha256']));self::assertWPError($photo->approveRender($f['session'],$f['selection']['sha256'],1,'Approval blocked during read lease'));self::assertWPError((new CustomerPhotoSelection())->review($f['session'],$f['selection']['sha256'],1,'REJECTED','Blocked during producer lease'));
   $wpdb=$primary;self::assertNotFalse($primary->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));self::assertWPError((new CustomerPhotoSelection())->forOrder('personalized_pod',$f['order'],$f['line']),'A producer lease must not authorize caller-owned transactions.');self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));$primary->query('ROLLBACK');
  }finally {$wpdb=$primary;$primary->query('ROLLBACK');self::assertTrue($fence->release());$other->close();wp_set_current_user($f['actor']);}
  wp_set_current_user($f['customer']);$next=$this->checked((new CustomerEditorRepository())->saveFields($f['session'],$f['version']['sha256'],['label'=>'After release']));self::assertNotSame($f['version']['sha256'],$next['sha256']);wp_set_current_user($f['actor']);self::assertWPError((new CustomerPhotoSelection())->forOrder('personalized_pod',$f['order'],$f['line']));
 }
 public static function sourceChanges():array {return [['revoke'],['mapping'],['schema']];}
 /** @dataProvider sourceChanges */
 public function testCommittedSourceChangesAreDeniedDespiteOldSnapshot(string $change):void {
  $f=$this->fixture();$photo=new CustomerPhotoSelection();
  $this->staleSnapshot(function($primary,$other)use($f,$photo,$change):void {
   global $wpdb;self::assertIsString($primary->get_var($primary->prepare("SELECT option_value FROM {$primary->options} WHERE option_name=%s",'df_photo_head_'.$f['line'])));
   $wpdb=$other;
   if($change==='revoke')$this->checked((new CustomerEditorRepository())->revoke($f['session'],$f['grant']['sha256']));
   elseif($change==='mapping')self::assertSame(1,$other->update(Tables::pod_mappings(),['state'=>'DRAFT'],['id'=>$f['selection']['snapshot']['provider_mapping_id']]));
   else self::assertSame(1,$other->update(Tables::personalization_schemas(),['state'=>'DRAFT'],['id'=>$f['selection']['snapshot']['schema_id']]));
   self::assertSame('0',(string)$other->get_var('SELECT @@session.in_transaction'));self::assertWPError($photo->forOrder('personalized_pod',$f['order'],$f['line']));
   $wpdb=$primary;self::assertWPError($photo->resolveForOrder('personalized_pod',$f['order'],$f['line']));self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));
  });
 }
 public function testCallerOwnedWritesAreNeverCommittedByProducerMutations():void {
  $f=$this->fixture();global $wpdb;$name='current-read-caller-write-'.wp_generate_uuid4();$wpdb->query('START TRANSACTION');$wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>'uncommitted','autoload'=>'no']);
  try {self::assertWPError((new CustomerEditorRepository())->revoke($f['session'],$f['grant']['sha256']));self::assertWPError((new CustomerPhotoSelection())->submit($f['session'],$f['version']['sha256'],1));self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));}
  finally {$wpdb->query('ROLLBACK');}
  self::assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name)));self::assertNotWPError((new CustomerEditorRepository())->access($f['session'],true));
 }

 public function testMissingOrCorruptSourceBytesNeverBecomeLegacyFallback():void {
  $f=$this->fixture();$service=new CustomerPhotoSelection();$path=\DigiForge\ProductFactory\AssetStorage::absolutePath($f['version']['images']['photo']['cutout']['storage_reference']);self::assertNotNull($path);$bytes=file_get_contents($path);
  try {unlink($path);self::assertWPError($service->resolveForOrder('personalized_pod',$f['order'],$f['line']));file_put_contents($path,'corrupt');self::assertWPError($service->resolveForOrder('personalized_pod',$f['order'],$f['line']));}
  finally {file_put_contents($path,$bytes);}
  self::assertSame($f['selection']['sha256'],$this->checked($service->forOrder('personalized_pod',$f['order'],$f['line']))['sha256']);
 }
 public static function renderWriters():array {return [['create'],['approve']];}
 /** @dataProvider renderWriters */
 public function testDirectRenderWriterRetainsProducerFenceThroughActualWrite(string $operation):void {
  $f=$this->fixture();$photo=new CustomerPhotoSelection();$repo=new \DigiForge\POD\RenderEvidenceRepository();$input=$f['input'];$input['personalization_evidence_hash']=$f['selection']['snapshot']['payload_sha256'];$render=null;
  if($operation==='approve'){$this->checked($photo->approvePhotoLayout($f['session'],$f['selection']['sha256'],1,$input['template_key'],1,'Synthetic explicit geometry'));$render=$this->checked($photo->prepare($f['session'],$f['selection']['sha256'],1,$input['template_key'],1));}
  global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$interleaved=false;$denied=null;
  $hook=static function($sql)use($primary,$other,$operation,&$interleaved,&$denied){if(!$interleaved&&str_contains($sql,Tables::pod_render_evidence())&&str_starts_with($sql,$operation==='create'?'INSERT INTO':'UPDATE')){global $wpdb;$interleaved=true;try{$wpdb=$other;$lease=\DigiForge\POD\CustomerPhotoProducerFence::acquireRead();$denied=is_wp_error($lease);if(!$denied)$lease->release();}finally{$wpdb=$primary;}}return $sql;};
  add_filter('query',$hook);try {$result=$operation==='create'?$repo->create($input):$repo->approve((int)$render['id']);$this->checked($result);self::assertTrue($interleaved,'The test must reach the real render write.');self::assertTrue($denied,'A direct render writer must prevent a second connection entering its producer fence until its actual write completes.');}
  finally {remove_filter('query',$hook);$wpdb=$primary;$other->close();}
 }

 public function testProducerMutationReleaseAcknowledgementFailureDeniesCommittedResult():void {
  $f=$this->fixture();global $wpdb;$fault=null;$injected=false;$fault=static function($sql)use(&$fault,&$injected,$wpdb){if(str_starts_with($sql,'SELECT RELEASE_LOCK(')&&str_contains($sql,'df-photo-producer-')){remove_filter('query',$fault);$injected=true;$wpdb->get_var($sql);return 'SELECT 0';}return $sql;};
  wp_set_current_user($f['customer']);add_filter('query',$fault);try{$result=(new CustomerEditorRepository())->saveFields($f['session'],$f['version']['sha256'],['label'=>'Synthetic committed lost release ack']);self::assertTrue($injected);self::assertWPError($result,'An uncertain producer fence release must not report an acknowledged success.');}
  finally {remove_filter('query',$fault);wp_set_current_user($f['actor']);}
  self::assertNotSame($f['version']['sha256'],$this->checked((new CustomerEditorRepository())->photoHead($f['session']))['sha256'],'The denial must not pretend that an already committed write rolled back.');
 }

 public static function retainedReadModes():array{return [[true,true],[false,true],[true,false],[false,false]];}
 /** @dataProvider retainedReadModes */
 public function testRetainedReadLeaseCanWitnessCallerTransactionWithoutReadingOrCommitting(bool $managed,bool $explicit):void {
  $f=$this->fixture($managed);$photo=new CustomerPhotoSelection();$fence=DigiForge\POD\CustomerPhotoProducerFence::acquireRead();self::assertNotWPError($fence);self::assertTrue(method_exists($fence,'validateRetainedReadLease'),'A lease-only consumer witness is required.');global $wpdb;$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$probe='retained-caller-'.wp_generate_uuid4();$queries=[];$capture=static function($sql)use(&$queries){$queries[]=$sql;return $sql;};
  try{
   $before=$photo->resolveForOrder('personalized_pod',$f['order'],$f['line']);if($managed)self::assertSame($f['selection']['sha256'],$before['sha256']);else self::assertNull($before);self::assertNotFalse($primary->query($explicit?'START TRANSACTION WITH CONSISTENT SNAPSHOT':'SET autocommit=0'));self::assertSame(1,$primary->insert($primary->options,['option_name'=>$probe,'option_value'=>'not-committed','autoload'=>'no']));add_filter('query',$capture);try{self::assertTrue($fence->validateRetainedReadLease());}finally{remove_filter('query',$capture);}self::assertCount(2,$queries);foreach($queries as $sql)self::assertTrue(str_starts_with($sql,'SELECT CONNECTION_ID()')||str_starts_with($sql,'SELECT IS_USED_LOCK('),'Lease witness may query session/lock metadata only.');self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$probe)));self::assertWPError($fence->validate());self::assertWPError($photo->resolveForOrder('personalized_pod',$f['order'],$f['line']));self::assertWPError(DigiForge\POD\CustomerPhotoProducerFence::acquireRead());
   $wpdb=$other;wp_set_current_user($f['customer']);$request=$f['request'];$request['request_key']='blocked-retained-dependency';$request['expected_head']=$managed?$f['version']['sha256']:null;self::assertWPError((new CustomerEditorRepository())->upload($f['session'],$request));$wpdb=$primary;wp_set_current_user($f['actor']);self::assertTrue($fence->validateRetainedReadLease());
  }finally{remove_filter('query',$capture);$wpdb=$primary;$primary->query('ROLLBACK');$primary->query('SET autocommit=1');self::assertTrue($fence->release());$other->close();wp_set_current_user($f['actor']);}self::assertWPError($fence->validateRetainedReadLease());self::assertNull($primary->get_var($primary->prepare('SELECT option_value FROM '.$primary->options.' WHERE option_name=%s',$probe)));
 }

 public static function reviewerRevocations():array{return [['capability'],['signed_grants']];}
 /** @dataProvider reviewerRevocations */
 public function testRetainedPhotoLeaseNeverSubstitutesForCurrentReviewerAuthority(string $change):void {
  $f=$this->fixture();$reviewer=$f['actor'];$consumer=self::factory()->user->create(['role'=>'administrator']);ShopAccessFixture::authorizeActor($consumer,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_orders']);global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));wp_set_current_user($consumer);$photo=new CustomerPhotoSelection();$fence=DigiForge\POD\CustomerPhotoProducerFence::acquireRead();self::assertNotWPError($fence);$primary=$wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($primary->prefix);$probe='review-authority-caller-'.wp_generate_uuid4();
  try{
   $before=$this->checked($photo->forOrder('personalized_pod',$f['order'],$f['line'],1));self::assertSame($reviewer,$before['review_provenance']['reviewer_user_id']);self::assertNotFalse($primary->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));self::assertSame(1,$primary->insert($primary->options,['option_name'=>$probe,'option_value'=>'uncommitted','autoload'=>'no']));
   $wpdb=$other;
   if($change==='capability'){
    $user=get_user_by('id',$reviewer);self::assertInstanceOf(WP_User::class,$user);$user->add_cap('manage_digiforge_orders',false);$stored=$other->get_var($other->prepare('SELECT meta_value FROM '.$other->usermeta.' WHERE user_id=%d AND meta_key=%s',$reviewer,$other->prefix.'capabilities'));self::assertFalse(maybe_unserialize($stored)['manage_digiforge_orders']);
   }else{
    $acl=new DigiForge\Security\ShopAccessRepository();$state=$this->checked($acl->read('personalized_pod'));$revoked=0;
    foreach($state['grants']as $id=>$grant){if($grant['actor_id']!==$reviewer||$grant['revoked'])continue;wp_set_current_user($grant['grantor_id']);$fresh=$this->checked($acl->read('personalized_pod'));$this->checked($acl->revoke('personalized_pod',$id,str_replace('-','_',wp_generate_uuid4()),$fresh['revision']));$revoked++;}
    self::assertGreaterThan(0,$revoked);$fresh=$this->checked($acl->read('personalized_pod'));foreach($fresh['grants']as $grant)if($grant['actor_id']===$reviewer)self::assertTrue($grant['revoked']);
   }
   self::assertSame('0',(string)$other->get_var('SELECT @@session.in_transaction'),'Reviewer revocation must really be committed on the other connection.');self::assertNull($other->get_var($other->prepare('SELECT option_value FROM '.$other->options.' WHERE option_name=%s',$probe)));
   $wpdb=$primary;wp_set_current_user($consumer);self::assertTrue($fence->validateRetainedReadLease(),'A photo-byte lease remains valid; it does not freeze reviewer roles or signed grants.');self::assertWPError($photo->forOrder('personalized_pod',$f['order'],$f['line'],1),'A retained pre-transaction proof cannot authorize a managed read inside the caller transaction.');self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));self::assertNotFalse($primary->query('ROLLBACK'));
   self::assertTrue(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_pod',$consumer),'The consumer itself remains assigned; denial must concern the independent reviewer.');self::assertWPError(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_orders',$reviewer));self::assertWPError($photo->forOrder('personalized_pod',$f['order'],$f['line'],1),'Fresh approved admission must deny the revoked independent reviewer.');self::assertTrue($fence->validateRetainedReadLease());
  }finally{$wpdb=$primary;$primary->query('ROLLBACK');self::assertTrue($fence->release());$other->close();wp_set_current_user($f['actor']);}self::assertNull($primary->get_var($primary->prepare('SELECT option_value FROM '.$primary->options.' WHERE option_name=%s',$probe)));
 }

 public function testRetainedWitnessRejectsMutationLeasesAndUncertainOwnership():void {
  global $wpdb;$mutation=DigiForge\POD\CustomerPhotoProducerFence::acquireMutation();self::assertNotWPError($mutation);try{self::assertWPError($mutation->validateRetainedReadLease());self::assertTrue($mutation->startMutationTransaction());self::assertTrue($mutation->validate());self::assertWPError($mutation->validateRetainedReadLease());}finally{$wpdb->query('ROLLBACK');self::assertTrue($mutation->release());}
  $read=DigiForge\POD\CustomerPhotoProducerFence::acquireRead();self::assertNotWPError($read);$fault=static fn($sql)=>str_starts_with($sql,'SELECT IS_USED_LOCK(')?'SELECT NULL':$sql;add_filter('query',$fault);try{self::assertWPError($read->validateRetainedReadLease());}finally{remove_filter('query',$fault);self::assertTrue($read->release());}self::assertWPError($read->validateRetainedReadLease());
 }


 private function podOnlyFixture():array {
  $f=$this->fixture();$acl=new DigiForge\Security\ShopAccessRepository();
  foreach(['digital','standard_pod','jewelry']as $shop){$state=$this->checked($acl->read($shop));foreach($state['grants']as $id=>$grant){if($grant['actor_id']!==$f['actor']||$grant['revoked'])continue;wp_set_current_user($grant['grantor_id']);$fresh=$this->checked($acl->read($shop));$this->checked($acl->revoke($shop,$id,str_replace('-','_',wp_generate_uuid4()),$fresh['revision']));}}
  wp_set_current_user($f['actor']);self::assertSame(['personalized_pod'],DigiForge\Security\ShopAccess::allowedShops('manage_digiforge_pod'));return $f;
 }
 private function operatorRequest(array $f,string $action,array $body):WP_REST_Request {
  $r=new WP_REST_Request('POST','/digiforge/v1/customer-editor/'.$f['session'].'/'.$action);$r->set_header('Content-Type','application/json');$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));$r->set_body(wp_json_encode($body));return $r;
 }
 public function testPodOnlyRegisteredPublishPreviewAndRevokePreserveExactPhotoBinding():void {
  $f=$this->podOnlyFixture();$calls=[];$deny=static function($pre,$args,$url)use(&$calls){$calls[]=$url;return new WP_Error('offline_only');};add_filter('pre_http_request',$deny,PHP_INT_MAX,3);
  try{
   $p=['shop_key'=>'personalized_pod','selection_sha256'=>$f['selection']['sha256'],'expected_revision'=>1];$t=['template_key'=>$f['input']['template_key'],'template_version'=>1];
   $layout=rest_do_request($this->operatorRequest($f,'publish',['operation'=>'PHOTO_LAYOUT']+$p+$t+['note'=>'Synthetic local geometry review only']));self::assertSame(200,$layout->get_status());
   $prepared=rest_do_request($this->operatorRequest($f,'publish',['operation'=>'PHOTO_PREPARE']+$p+$t));self::assertSame(200,$prepared->get_status());$render=$prepared->get_data();self::assertSame('UNREVIEWED',$render['review_status']);
   $receipt=$this->checked((new DigiForge\POD\RenderArtifactVerifier())->assertCertified($render));self::assertSame($receipt['outputs']['front']['sha256'],$receipt['buyer_previews']['front']['sha256']);
   $approved=rest_do_request($this->operatorRequest($f,'publish',['operation'=>'PHOTO_APPROVE_RENDER']+$p+['note'=>'Synthetic exact local artifact review only']));self::assertSame(200,$approved->get_status());
   wp_set_current_user($f['customer']);$preview=$this->checked((new CustomerPhotoSelection())->buyerPreview($f['session'],$f['version']['sha256']));self::assertSame($receipt['outputs']['front']['sha256'],$preview['artifact_sha256']);self::assertFalse($preview['supplier_certified']);
   $approval=rest_do_request($this->operatorRequest($f,'approve',['version_sha256'=>$f['version']['sha256'],'context_sha256'=>$preview['context_sha256']]));self::assertSame(200,$approval->get_status());self::assertFalse($approval->get_data()['external_execution_authorized']);
   wp_set_current_user($f['actor']);$revoked=rest_do_request($this->operatorRequest($f,'revoke',['access_sha256'=>$f['grant']['sha256']]));self::assertSame(200,$revoked->get_status());
   $denied=rest_do_request($this->operatorRequest($f,'intake',['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod']));self::assertSame(403,$denied->get_status());wp_set_current_user($f['customer']);self::assertWPError((new CustomerPhotoSelection())->buyerPreview($f['session'],$f['version']['sha256']));self::assertSame([],$calls);
  }finally{remove_filter('pre_http_request',$deny,PHP_INT_MAX);wp_set_current_user($f['actor']);}
 }
 public function testPodOnlyOperatorScopeRejectsAllClaimCarriersAndBuyerOperatorConfusion():void {
  $f=$this->podOnlyFixture();$body=['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod'];
  foreach(['query','body','json']as $carrier)foreach(['session'=>'other_session','shop'=>'digital','shop_key'=>['personalized_pod']]as $field=>$value){$r=$this->operatorRequest($f,'intake',$body);if($carrier==='query')$r->set_query_params([$field=>$value]);elseif($carrier==='body')$r->set_body_params([$field=>$value]);else $r->set_body(wp_json_encode(array_replace($body,[$field=>$value])));// Core REST argument validation rejects the malformed JSON array before scope admission.
   self::assertSame($carrier==='json'&&is_array($value)?400:403,rest_do_request($r)->get_status(),$carrier.':'.$field);}
  wp_set_current_user($f['customer']);foreach(['intake','publish','revoke']as $action)self::assertSame(403,rest_do_request($this->operatorRequest($f,$action,$body))->get_status());
  wp_set_current_user($f['actor']);$r=$this->operatorRequest($f,'intake',$body);$r->set_header('X-WP-Nonce','invalid');self::assertSame(403,rest_do_request($r)->get_status());self::assertSame(200,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());
 }
 public function testPodOnlyOperatorScopeRejectsCorruptForeignAndCallerSnapshotWithoutCommit():void {
  $f=$this->podOnlyFixture();$body=['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod'];$path=DigiForge\ProductFactory\AssetStorage::absolutePath($f['grant']['proof_reference']);self::assertNotNull($path);$bytes=file_get_contents($path);
  try{file_put_contents($path,'{}');self::assertSame(403,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());}finally{file_put_contents($path,$bytes);}
  global $wpdb;$probe='rest-scope-caller-'.wp_generate_uuid4();self::assertNotFalse($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT'));
  try{self::assertSame(1,$wpdb->insert($wpdb->options,['option_name'=>$probe,'option_value'=>'uncommitted','autoload'=>'no']));$denied=rest_do_request($this->operatorRequest($f,'intake',$body));self::assertSame(409,$denied->get_status());self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));}finally{self::assertNotFalse($wpdb->query('ROLLBACK'));}self::assertNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$probe)));
  $old=$wpdb->get_var($wpdb->prepare('SELECT shop_reference FROM '.Tables::orders().' WHERE id=%d',$f['order']));try{self::assertSame(1,$wpdb->update(Tables::orders(),['shop_reference'=>'digital'],['id'=>$f['order']]));self::assertGreaterThanOrEqual(400,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());}finally{self::assertSame(1,$wpdb->update(Tables::orders(),['shop_reference'=>$old],['id'=>$f['order']]));}self::assertSame(200,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());
 }
 public function testPodOnlyOperatorScopeCannotRetainRevokedActorAdmission():void {
  $f=$this->podOnlyFixture();$body=['operation'=>'PHOTO_VIEW','shop_key'=>'personalized_pod'];self::assertSame(200,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());
  $actor=get_user_by('id',$f['actor']);$actor->add_cap('manage_digiforge_pod',false);try{self::assertSame(403,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());}finally{$actor->add_cap('manage_digiforge_pod');}
  $acl=new DigiForge\Security\ShopAccessRepository();$state=$this->checked($acl->read('personalized_pod'));foreach($state['grants']as $id=>$grant){if($grant['actor_id']!==$f['actor']||$grant['revoked'])continue;wp_set_current_user($grant['grantor_id']);$fresh=$this->checked($acl->read('personalized_pod'));$this->checked($acl->revoke('personalized_pod',$id,str_replace('-','_',wp_generate_uuid4()),$fresh['revision']));}wp_set_current_user($f['actor']);self::assertSame([],DigiForge\Security\ShopAccess::allowedShops('manage_digiforge_pod'));self::assertSame(403,rest_do_request($this->operatorRequest($f,'intake',$body))->get_status());
 }

}
