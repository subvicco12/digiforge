<?php
declare(strict_types=1);
require_once __DIR__.'/ExternalDocumentAdmissionTest.php';
use DigiForge\DigitalFactory\DriveArtifactTransfer as Transfer;
use DigiForge\DigitalFactory\DriveOfflineTransport;
use DigiForge\DigitalFactory\DriveTransferRequest as Request;
use DigiForge\Database\Tables;
use DigiForge\Operations\RecoveryDispatchLedger as Ledger;

/** In-memory normalized responses only. Cannot contact Google or access credentials. */
final class DriveFixtureTransport implements DriveOfflineTransport {
 public array $calls=[];public array $created=[];public ?Closure $alter=null;
 public function __construct(public string $pdf){}
 public function exchange(array $request,string $sourceBytes):array {
  $this->calls[]=$request;$stage=$request['stage'];
  if($stage==='folder')$r=['status'=>200,'metadata'=>['id'=>Request::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]]];
  elseif(in_array($stage,['original_upload','native_conversion'],true)){
   $m=$request['metadata']+['id'=>$stage==='original_upload'?'synthetic_original':'synthetic_native','version'=>'1','trashed'=>false,'capabilities'=>['canDownload'=>true]];$this->created[$stage]=$m;$r=['status'=>200,'metadata'=>$m];
  }elseif($stage==='original_readback')$r=['status'=>200,'bytes'=>$sourceBytes];
  elseif($stage==='pdf_export')$r=['status'=>200,'bytes'=>$this->pdf];
  elseif(str_starts_with($stage,'reconcile_'))$r=['status'=>200,'files'=>isset($this->created[substr($stage,10)])?[$this->created[substr($stage,10)]]:[]];
  else $r=['status'=>200,'metadata'=>$this->created['native_conversion']];
  return $this->alter?($this->alter)($stage,$r,$request):$r;
 }
}
final class DriveArtifactTransferTest extends WP_UnitTestCase {
 private array $paths=[];private Closure $deny;private int $network=0;
 protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$this->deny=function(){++$this->network;return new WP_Error('google_network_forbidden');};add_filter('pre_http_request',$this->deny);}
 protected function tearDown():void {remove_filter('pre_http_request',$this->deny);foreach($this->paths as$p)@unlink($p);self::assertSame(0,$this->network);self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());parent::tearDown();}
 public function testScopedOfflineTransferCapabilityExists():void {self::assertTrue(class_exists(Transfer::class),'Missing governed Digital artifact-to-Drive offline transfer workflow.');}
 private function fixture(?string $paper=null,bool $optional=true):array {
  ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);
  $r=(new ReflectionMethod(ExternalDocumentAdmissionTest::class,'documentRequest'))->invoke(new ExternalDocumentAdmissionTest());$this->paths[]= $r['source_path'];$this->paths[]= $r['export_path'];
  if($paper!==null){
   //52 named fields, nine explicitly bounded pages. No Wedding v0.5 compatibility claim.
   $paragraphs=[];$pages=[];$current=[];
   for($i=1;$i<=52;$i++){$text='[[FIELD_'.sprintf('%02d',$i).']]';if(!$optional&&$i===52)$text='Optional field removed';$paragraphs[]='<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p>';$current[]=$text;if(count($current)===6||$i===52){$pages[]=implode("\n",$current);$current=[];}}
   $size=$paper==='A4'?'11900':'12240';$height=$paper==='A4'?'16840':'15840';
   $zip=new ZipArchive();self::assertTrue($zip->open($r['source_path'],ZipArchive::OVERWRITE)===true);$zip->addFromString('word/document.xml','<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('',$paragraphs).'<w:sectPr><w:pgSz w:w="'.$size.'" w:h="'.$height.'"/><w:pgMar w:top="720" w:bottom="720" w:left="720" w:right="720"/></w:sectPr></w:body></w:document>');$zip->close();
   $asset=(new DigiForge\ProductFactory\LocalAssetProducer())->write($r['product_version_id'],'synthetic-'.$paper.'.pdf','pdf',$pages,'drive-'.wp_generate_uuid4());self::assertNotWPError($asset);self::assertTrue(copy($asset['absolute_path'],$r['export_path']));
   $r['source_sha256']=hash_file('sha256',$r['source_path']);$r['export_sha256']=hash_file('sha256',$r['export_path']);
  }
  $a=(new DigiForge\ProductFactory\ExternalDocumentAdmission())->admit($r);self::assertNotWPError($a);$repo=new DigiForge\Production\Repository();$revision=$repo->addRevision($a['revision_input']);self::assertNotWPError($revision);self::assertNotWPError($repo->addQa(['target_type'=>'revision','target_id'=>$revision['id'],'check_type'=>'external_document_source_completeness','status'=>'PASS','details'=>$a['evidence']+['binding'=>$a['binding']]]));
  return ['request'=>$r,'admitted'=>$a,'revision'=>$revision];
 }
 private function prepare(array $f,string $key=''):array|WP_Error {return (new Transfer())->prepare((int)$f['revision']['id'],$f['request']['product_id'],$f['request']['product_version_id'],$f['request']['source_sha256'],$key?:'fixture_'.str_replace('-','',wp_generate_uuid4()));}
 private function adapter(array $f):DriveFixtureTransport {return new DriveFixtureTransport(file_get_contents($f['request']['export_path']));}
 public function testOriginalPreservationConversionReadbackAndReplayAreSyntheticOnly():void {
  $f=$this->fixture();$service=new Transfer();$key='same_'.str_replace('-','',wp_generate_uuid4());$p=$this->prepare($f,$key);self::assertNotWPError($p);self::assertSame($p,$this->prepare($f,$key));self::assertSame($p,$this->prepare($f,'new_request_same_source'));$adapter=$this->adapter($f);$done=$service->runOffline($p['id'],$adapter);self::assertNotWPError($done);self::assertSame('OFFLINE_VERIFIED',$done['state']);self::assertSame('UNKNOWN',$done['provider_acceptance']);self::assertFalse($done['external_actions_performed']);self::assertFalse($done['commercial_approval']);self::assertNotSame($done['original_file_id'],$done['native_file_id']);self::assertCount(7,$adapter->calls);self::assertSame($done,$service->runOffline($p['id'],$adapter));self::assertCount(7,$adapter->calls);self::assertWPError($service->execute($p['id']));self::assertSame($f['request']['source_sha256'],hash_file('sha256',$f['admitted']['source']['absolute_path']));
  foreach($adapter->calls as$request){self::assertFalse($request['network_request_permitted']);self::assertSame(0,$request['redirection']);self::assertTrue($request['sslverify']);self::assertNotContains($request['method'],['PATCH','DELETE']);}
 }
 public static function papers():array{return [['Letter',true],['A4',true],['Letter',false]];}
 /** @dataProvider papers */
 public function testSynthetic52FieldNinePageExportIsSourceBound(string $paper,bool $optional):void {$f=$this->fixture($paper,$optional);$p=$this->prepare($f);self::assertNotWPError($p);$r=(new Transfer())->runOffline($p['id'],$this->adapter($f));self::assertNotWPError($r);self::assertSame(9,$r['technical_qa']['page_count']);self::assertSame($f['request']['source_sha256'],$r['technical_qa']['source_sha256']);}
 public function testForeignProductHashFolderAndUnassignedActorDeny():void {
  $f=$this->fixture();$s=new Transfer();self::assertWPError($s->prepare((int)$f['revision']['id'],$f['request']['product_id']+999,$f['request']['product_version_id'],$f['request']['source_sha256'],'bad_product'));self::assertWPError($s->prepare((int)$f['revision']['id'],$f['request']['product_id'],$f['request']['product_version_id'],str_repeat('0',64),'bad_hash'));self::assertWPError($s->prepare((int)$f['revision']['id'],$f['request']['product_id'],$f['request']['product_version_id'],$f['request']['source_sha256'],'bad_folder','foreign_folder'));$p=$this->prepare($f);self::assertNotWPError($p);ShopAccessFixture::reset();$adapter=$this->adapter($f);self::assertWPError($s->runOffline($p['id'],$adapter));self::assertCount(0,$adapter->calls);
 }
 public function testRevokedDuringResponseAndAlteredSourceDeny():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static function($stage,$r){ShopAccessFixture::reset();return $r;};self::assertWPError((new Transfer())->runOffline($p['id'],$a));self::assertCount(1,$a->calls);
  ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_products']);$p=$this->prepare($f);file_put_contents($f['admitted']['source']['absolute_path'],'tampered');self::assertWPError((new Transfer())->runOffline($p['id'],$this->adapter($f)));
 }
 /** @dataProvider uncertainCases */
 public function testTimeoutAndRateLimitNeverBlindlyRepeatCreate(string $stage,int $status):void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static fn($s,$r)=>$s===$stage?['status'=>$status,'error'=>'SYNTHETIC_SECRET_MUST_NOT_PERSIST']:$r;
  $first=(new Transfer())->runOffline($p['id'],$a);self::assertSame('UNKNOWN',$first['state']);$a->alter=null;$resumed=(new Transfer())->runOffline($p['id'],$a);self::assertNotWPError($resumed);self::assertSame('OFFLINE_VERIFIED',$resumed['state']);self::assertCount(1,array_filter($a->calls,static fn($r)=>$r['stage']===$stage));self::assertCount(1,array_filter($a->calls,static fn($r)=>$r['stage']==='reconcile_'.$stage));
  global$wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s','digiforge_drive_offline_'.$p['id'].'_%'),ARRAY_A);self::assertStringNotContainsString('SYNTHETIC_SECRET_MUST_NOT_PERSIST',wp_json_encode($rows));
 }
 public static function uncertainCases():array{return [['original_upload',0],['native_conversion',429],['native_conversion',503]];}
 public function testAmbiguousLookupAndMissingDispatchEvidenceRemainBlocked():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static fn($s,$r)=>$s==='native_conversion'?['status'=>0]:$r;self::assertSame('UNKNOWN',(new Transfer())->runOffline($p['id'],$a)['state']);$a->alter=static function($s,$r){if($s==='reconcile_native_conversion')$r['files'][]=$r['files'][0];return $r;};self::assertSame('UNKNOWN',(new Transfer())->runOffline($p['id'],$a)['state']);$calls=count($a->calls);self::assertSame('UNKNOWN',(new Transfer())->runOffline($p['id'],$a)['state']);self::assertCount($calls,$a->calls);
  global$wpdb;self::assertSame(1,$wpdb->delete($wpdb->options,['option_name'=>'digiforge_drive_offline_'.$p['id'].'_native_conversion-intent']));$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');self::assertWPError((new Transfer())->runOffline($p['id'],$a));
 }
 public static function badResponses():array {return [['folder'],['wrong_parent'],['wrong_marker'],['wrong_original_bytes'],['changed_version'],['bad_pdf']];}
 /** @dataProvider badResponses */
 public function testMalformedOrDriftedProviderEvidenceCannotPass(string $kind):void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static function($stage,$r)use($kind){if($kind==='folder'&&$stage==='folder')$r['metadata']['id']='foreign';if($kind==='wrong_parent'&&$stage==='native_conversion')$r['metadata']['parents']=['foreign'];if($kind==='wrong_marker'&&$stage==='original_upload')$r['metadata']['appProperties']['source_sha256']=str_repeat('0',64);if($kind==='wrong_original_bytes'&&$stage==='original_readback')$r['bytes']='corrupted';if($kind==='changed_version'&&$stage==='native_final')$r['metadata']['version']='2';if($kind==='bad_pdf'&&$stage==='pdf_export')$r['bytes']='invalid';return $r;};$r=(new Transfer())->runOffline($p['id'],$a);self::assertTrue($r instanceof WP_Error||$r['state']==='UNKNOWN');
 }
 public function testDeletedOrExpiredPlanDeny():void {
  $f=$this->fixture();$key='missing_'.str_replace('-','',wp_generate_uuid4());$p=$this->prepare($f,$key);self::assertNotWPError($p);global$wpdb;$name='digiforge_drive_offline_'.$p['id'].'_plan';self::assertSame(1,$wpdb->delete($wpdb->options,['option_name'=>$name]));$wpdb->query('COMMIT');$wpdb->query('START TRANSACTION');self::assertWPError($this->prepare($f,$key));self::assertWPError((new Transfer())->runOffline($p['id'],$this->adapter($f)));
  $f=$this->fixture();$p=$this->prepare($f);self::assertNotWPError($p);$expired=$p;$expired['expires_at']=time()-1;self::assertTrue(Ledger::compareAndSwap('digiforge_drive_offline_'.$p['id'].'_plan',$p,$expired));self::assertWPError((new Transfer())->runOffline($p['id'],$this->adapter($f)));
 }
 public function testDatabaseFailureAndResponseExceptionStayRedacted():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static function($stage,$r){throw new RuntimeException('SYNTHETIC_SECRET_MUST_NOT_PERSIST');};$r=(new Transfer())->runOffline($p['id'],$a);self::assertSame('UNKNOWN',$r['state']);self::assertStringNotContainsString('SYNTHETIC_SECRET',wp_json_encode($r));
  global$wpdb;$filter=static function($q){return str_contains($q,'SELECT * FROM '.Tables::asset_revisions())?'SELECT missing_scope_column FROM '.Tables::asset_revisions():$q;};$old=$wpdb->suppress_errors(true);add_filter('query',$filter);try{self::assertWPError($this->prepare($f));}finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
 }
 public function testNestedConcurrentRunnerCannotClaimSameUploadTwice():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$other=$this->adapter($f);$nested=null;$a->alter=static function($stage,$r)use($p,$other,&$nested){if($stage==='original_upload')$nested=(new Transfer())->runOffline($p['id'],$other);return $r;};self::assertSame('OFFLINE_VERIFIED',(new Transfer())->runOffline($p['id'],$a)['state']);self::assertSame('UNKNOWN',$nested['state']);self::assertSame('original_upload',$nested['blocked_stage']);self::assertCount(0,$other->calls);
 }
 public function testNativeMetadataKeyOrderDoesNotChangeIdentity():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static function($stage,$r){if(isset($r['metadata']['appProperties']))$r['metadata']['appProperties']=array_reverse($r['metadata']['appProperties'],true);return $r;};self::assertSame('OFFLINE_VERIFIED',(new Transfer())->runOffline($p['id'],$a)['state']);
 }
 public function testReadTimeoutResumesWithOneBoundedReadRetryOnly():void {
  $f=$this->fixture();$p=$this->prepare($f);$a=$this->adapter($f);$a->alter=static fn($s,$r)=>$s==='pdf_export'?['status'=>429]:$r;self::assertSame('UNKNOWN',(new Transfer())->runOffline($p['id'],$a)['state']);$a->alter=null;self::assertSame('OFFLINE_VERIFIED',(new Transfer())->runOffline($p['id'],$a)['state']);self::assertCount(2,array_filter($a->calls,static fn($r)=>$r['stage']==='pdf_export'));self::assertCount(1,array_filter($a->calls,static fn($r)=>$r['stage']==='native_conversion'));
 }
 /** @dataProvider unsupportedEnvironments */
 public function testUnsupportedEnvironmentDeniesBeforeWordPressOrCredentialAccess(string $env):void {
  $code='require '.var_export(ABSPATH.'wp-includes/plugin.php',true).';require '.var_export(ABSPATH.'wp-includes/class-wp-error.php',true).';function wp_get_environment_type(){return '.var_export($env,true).';}require '.var_export(dirname(__DIR__,2).'/includes/DigitalFactory/DriveArtifactTransfer.php',true).';$r=(new DigiForge\DigitalFactory\DriveArtifactTransfer())->execute(str_repeat("a",64));echo $r->get_error_code();';
  $process=proc_open([PHP_BINARY,'-r',$code],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err.$out);self::assertSame('',$err);self::assertSame('digiforge_drive_scope',$out);
 }
 public static function unsupportedEnvironments():array{return [['production'],['staging'],['development']];}
}
