<?php
declare(strict_types=1);
require_once __DIR__.'/RenderArtifactCertificationTest.php';
require_once __DIR__.'/fixtures/PhotoWorkflowFixtureIsolation.php';
use DigiForge\Database\Tables;
use DigiForge\POD\{CustomerEditorRepository,CustomerPhotoSelection,RasterArtworkCodec};

/** Real connections and public producer operations; no fabricated photo heads or resolver stand-ins. */
final class CustomerPhotoCurrentReadTest extends WP_UnitTestCase {
 use PhotoWorkflowFixtureIsolation;
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();ShopAccessFixture::reset();$this->capturePhotoFixtureState();global $wpdb;self::assertNotFalse($wpdb->query('COMMIT'));self::assertNotFalse($wpdb->query('SET autocommit=1'));}
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

 public function testRetainedWitnessRejectsMutationLeasesAndUncertainOwnership():void {
  global $wpdb;$mutation=DigiForge\POD\CustomerPhotoProducerFence::acquireMutation();self::assertNotWPError($mutation);try{self::assertWPError($mutation->validateRetainedReadLease());self::assertTrue($mutation->startMutationTransaction());self::assertTrue($mutation->validate());self::assertWPError($mutation->validateRetainedReadLease());}finally{$wpdb->query('ROLLBACK');self::assertTrue($mutation->release());}
  $read=DigiForge\POD\CustomerPhotoProducerFence::acquireRead();self::assertNotWPError($read);$fault=static fn($sql)=>str_starts_with($sql,'SELECT IS_USED_LOCK(')?'SELECT NULL':$sql;add_filter('query',$fault);try{self::assertWPError($read->validateRetainedReadLease());}finally{remove_filter('query',$fault);self::assertTrue($read->release());}self::assertWPError($read->validateRetainedReadLease());
 }

}
