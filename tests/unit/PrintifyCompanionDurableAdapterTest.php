<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{VersionedMutationHost,MutationFence};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
final class PrintifyCompanionDurableAdapterTest extends TestCase {
 private string $path;
 protected function setUp():void {require_once __DIR__.'/../fixtures/printify-connector/batch2/DurableSyntheticMutationCore.php';$this->path=tempnam(sys_get_temp_dir(),'df-core-batch2-');unlink($this->path);}
 protected function tearDown():void{foreach(['','.lock','.tmp']as $suffix)@unlink($this->path.$suffix);}
 private function intent():DigiForgePrintifyConnector\Preparation\MutationIntent{return (require __DIR__.'/../fixtures/printify-connector/batch2/intent.php')();}
 public function testProcessReopenCannotReleaseCrashBeforeSendFence():void {$i=$this->intent();$h=new VersionedMutationHost(new DurableSyntheticMutationCore($this->path));$f=new MutationFence($h);self::assertIsArray($f->register($i));self::assertIsArray($f->reserve($i));$new=new VersionedMutationHost(new DurableSyntheticMutationCore($this->path));self::assertSame('FENCED',$new->recover($i)['state']);self::assertInstanceOf(WP_Error::class,(new MutationFence($new))->reserve($i));}
 public function testLostClaimAndReceiptAcknowledgementRemainDurable():void {$i=$this->intent();$c=new DurableSyntheticMutationCore($this->path);$h=new VersionedMutationHost($c);$f=new MutationFence($h);$f->register($i);$c->fault='lost_claim_ack';self::assertInstanceOf(WP_Error::class,$f->reserve($i));$c->fault='';self::assertSame('FENCED',$h->recover($i)['state']);self::assertInstanceOf(WP_Error::class,$f->reserve($i));$r=(new SyntheticMutationCore())->receipt($i);$c->fault='lost_receipt_ack';self::assertInstanceOf(WP_Error::class,$h->acceptReceipt($i,$r));$new=new VersionedMutationHost(new DurableSyntheticMutationCore($this->path));self::assertSame('METADATA_RECONCILED',$new->recover($i)['state']);self::assertSame('METADATA_RECONCILED',$new->acceptReceipt($i,$r)['state']);self::assertInstanceOf(WP_Error::class,(new MutationFence($new))->reserve($i));}
 public function testConcurrentAdapterClaimsOneWinner():void {$i=$this->intent();$f=new MutationFence(new VersionedMutationHost(new DurableSyntheticMutationCore($this->path)));$f->register($i);$children=[];
  for($n=0;$n<2;$n++){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../fixtures/printify-connector/batch2/claim-worker.php',$this->path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);$children[]=[$p,$pipes];}
  $wins=0;foreach($children as [$p,$pipes]){$o=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$o.$e);$wins+=(int)$o;}self::assertSame(1,$wins);
 }
 public function testUnavailableJournalCannotProduceSuccessfulRegistration():void {$i=$this->intent();$c=new DurableSyntheticMutationCore($this->path);$c->fault='storage_failure';self::assertInstanceOf(WP_Error::class,(new MutationFence(new VersionedMutationHost($c)))->register($i));self::assertFileDoesNotExist($this->path);}
}
