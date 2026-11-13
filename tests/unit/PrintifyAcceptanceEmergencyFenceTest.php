<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyAcceptanceEmergencyFence as Fence;
if(is_file(__DIR__.'/../../includes/POD/PrintifyAcceptanceEmergencyFence.php'))require_once __DIR__.'/../../includes/POD/PrintifyAcceptanceEmergencyFence.php';
final class PrintifyAcceptanceEmergencyFenceTest extends TestCase {
 private string $dir;
 protected function setUp():void {$this->dir=sys_get_temp_dir().'/df-fence-test-'.bin2hex(random_bytes(12));mkdir($this->dir,0700);file_put_contents($this->dir.'/events','DFPF1' . "\n" .str_repeat('a',64)."\n");chmod($this->dir.'/events',0600);file_put_contents($this->dir.'/dispatch.lock',"DF_DISPATCH_GATE_V1\n");chmod($this->dir.'/dispatch.lock',0600);$stat=stat($this->dir.'/dispatch.lock');file_put_contents($this->dir.'/dispatch.identity',"DFG1:".hash('sha256',$stat['dev'].':'.$stat['ino'])."\n");chmod($this->dir.'/dispatch.identity',0600);}
 protected function tearDown():void {foreach(glob($this->dir.'/*')?:[] as $p)unlink($p);rmdir($this->dir);}
 private function fence():Fence {self::assertTrue(class_exists(Fence::class),'Independent durable fence required');return new Fence($this->dir);}
 private function expectDenied(callable $work,string $message='ACCEPTANCE_FENCE_UNAVAILABLE'):void {$caught=null;try{$work();}catch(\RuntimeException $e){$caught=$e;}self::assertInstanceOf(\RuntimeException::class,$caught);self::assertSame($message,$caught->getMessage());}
 public function testInvalidationPermanentlyChangesSnapshotAcrossNewObjects():void {$f=$this->fence();$old=$f->snapshot();$new=$f->invalidate();self::assertNotSame($old,$new);self::assertSame($new,(new Fence($this->dir))->snapshot());self::assertNotSame($old,$f->invalidate());}
 public function testMissingFileIsNotCreatedByInspectionOrInvalidation():void {$f=$this->fence();unlink($this->dir.'/events');$this->expectDenied(fn()=>$f->invalidate());self::assertFileDoesNotExist($this->dir.'/events');}
 public function testTruncatedOrMalformedJournalDenies():void {$f=$this->fence();foreach(['','DFPF1\n','DFPF1'."\n".str_repeat('a',64)."\npartial",'unsafe'] as $raw){file_put_contents($this->dir.'/events',$raw);$this->expectDenied(fn()=>$f->snapshot());}}
 public function testSymlinkAndHardlinkRejected():void {$f=$this->fence();rename($this->dir.'/events',$this->dir.'/original');symlink($this->dir.'/original',$this->dir.'/events');$this->expectDenied(fn()=>$f->snapshot());unlink($this->dir.'/events');link($this->dir.'/original',$this->dir.'/events');$this->expectException(\RuntimeException::class);$f->snapshot();}
 public function testPermissiveDirectoryRejected():void {$f=$this->fence();chmod($this->dir,0755);try{$this->expectException(\RuntimeException::class);$f->snapshot();}finally{chmod($this->dir,0700);}}
 public function testPermissiveFileRejected():void {$f=$this->fence();chmod($this->dir.'/events',0644);$this->expectException(\RuntimeException::class);$f->snapshot();}
 public function testBoundedJournalRejectsExhaustion():void {$f=$this->fence();file_put_contents($this->dir.'/events',"DFPF1\n".str_repeat(str_repeat('b',64)."\n",513));$this->expectException(\RuntimeException::class);$f->invalidate();}
 public function testSharedDirectoryRejectedWithoutProvisioning():void {self::assertTrue(class_exists(Fence::class));$this->expectException(\RuntimeException::class);(new Fence(sys_get_temp_dir()))->snapshot();}
 public function testCommittedEventCannotBeHiddenByRestoringDatabaseValues():void {$f=$this->fence();$databaseGeneration=['fence_sha256'=>$f->snapshot()];$f->invalidate();self::assertNotSame($databaseGeneration['fence_sha256'],(new Fence($this->dir))->snapshot());}
 public function testEmergencyAtCapacityPermanentlyPoisonsRatherThanPreservesOldEpoch():void {$f=$this->fence();file_put_contents($this->dir.'/events',"DFPF1\n".str_repeat(str_repeat('a',64)."\n",512));self::assertIsString($f->snapshot());$this->expectDenied(fn()=>$f->invalidate());$this->expectException(\RuntimeException::class);(new Fence($this->dir))->snapshot();}
 public function testConcurrentProcessesCannotRestorePreviousEpoch():void {
  $f=$this->fence();$old=$f->snapshot();$children=[];$code='require $argv[1];try{(new \\DigiForge\\POD\\PrintifyAcceptanceEmergencyFence($argv[2]))->invalidate();exit(0);}catch(Throwable){exit(42);}';
  try{for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../../includes/POD/PrintifyAcceptanceEmergencyFence.php',$this->dir],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);fclose($pipes[0]);$children[]=[$p,$pipes];}
   foreach($children as [$p,$pipes]){self::assertSame('',stream_get_contents($pipes[1]));self::assertSame('',stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);self::assertContains(proc_close($p),[0,42]);}$children=[];self::assertNotSame($old,(new Fence($this->dir))->snapshot());
  }finally{foreach($children as [$p,$pipes]){if(is_resource($p)){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}}
 }
 public function testEmergencyIntentIsImmediateButDrainAckWaitsForAdmittedDispatch():void {
  $f=$this->fence();self::assertTrue(method_exists($f,'duringDispatch'),'Shared dispatch lease required');$old=$f->snapshot();$f->duringDispatch(function()use($f,$old):void{self::assertNotSame($old,$f->invalidate());$this->expectDenied(fn()=>$f->afterDraining(static fn()=>true),'ACCEPTANCE_FENCE_DRAIN_BUSY');});self::assertTrue($f->afterDraining(static fn()=>true));
 }
 public function testMalformedDispatchGateNeverInvokesWork():void {$f=$this->fence();self::assertTrue(method_exists($f,'duringDispatch'));file_put_contents($this->dir.'/dispatch.lock','invalid');$called=false;$this->expectDenied(function()use($f,&$called){return $f->duringDispatch(function()use(&$called){$called=true;});});self::assertFalse($called);}
 public function testFilesystemRootAsPublicRootRejectsPrivateDirectory():void {$f=$this->fence();$old=$_SERVER['DOCUMENT_ROOT']??null;$_SERVER['DOCUMENT_ROOT']='/';try{$this->expectException(\RuntimeException::class);$f->snapshot();}finally{if($old===null)unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$old;}}
 public function testReplacingDispatchGateCannotAcknowledgeOldLease():void {$f=$this->fence();$f->duringDispatch(function()use($f):void{rename($this->dir.'/dispatch.lock',$this->dir.'/old.lock');file_put_contents($this->dir.'/dispatch.lock',"DF_DISPATCH_GATE_V1\n");chmod($this->dir.'/dispatch.lock',0600);$this->expectDenied(fn()=>$f->afterDraining(static fn()=>true));});}
 public function testSeparateProcessLeaseBlocksAcknowledgementButNotEmergencyIntent():void {
  $f=$this->fence();$pipes=[];$code='require $argv[1];try{(new \\DigiForge\\POD\\PrintifyAcceptanceEmergencyFence($argv[2]))->duringDispatch(function(){echo "READY\\n";fflush(STDOUT);stream_set_timeout(STDIN,3);fgets(STDIN);});exit(0);}catch(Throwable){exit(42);}';$process=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../../includes/POD/PrintifyAcceptanceEmergencyFence.php',$this->dir],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
  try{self::assertSame("READY\n",fgets($pipes[1]));$old=$f->snapshot();self::assertNotSame($old,$f->invalidate());$this->expectDenied(fn()=>$f->afterDraining(static fn()=>true),'ACCEPTANCE_FENCE_DRAIN_BUSY');fwrite($pipes[0],"finish\n");fclose($pipes[0]);self::assertSame('',stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process));$process=null;self::assertTrue($f->afterDraining(static fn()=>true));}finally{if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
 }
}
