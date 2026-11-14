<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForgePrintifyConnector\Preparation\{PreparationService,MutationIntent,MutationFence};
require_once __DIR__.'/../fixtures/printify-connector/stage34/companion-test-bootstrap.php';
final class PrintifyCompanionMutationFenceTest extends TestCase {
 private function upload():MutationIntent {[$bytes,$e,$scope]=(require __DIR__.'/../fixtures/printify-connector/stage34/artwork-fixture.php')();return (new PreparationService())->upload($scope,$bytes,$e,['actor_id'=>1,'logical_reference'=>'core-asset-1','approval_reference'=>str_repeat('d',64),'authorization_revision'=>1,'product_version'=>1,'expected_product_version'=>0]);}
 public function testOfflineIntentIsImmutableNonAuthorizingAndSecretSafe():void {$i=$this->upload();self::assertSame('IMAGE_UPLOAD',$i->operation);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$i->operationId);self::assertFalse($i->jsonSerialize()['network_request_permitted']);self::assertArrayNotHasKey('body',$i->jsonSerialize());self::assertStringNotContainsString('contents',json_encode($i));self::assertSame($i->operationId,$this->upload()->operationId);}
 public function testDefaultHostCannotJournalOrFenceAnyWrite():void {$i=$this->upload();$f=new MutationFence();self::assertInstanceOf(WP_Error::class,$f->register($i));self::assertInstanceOf(WP_Error::class,$f->reserve($i));}
 public function testDurableHostRejectsDuplicateAfterReopen():void {$i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);try{$h=new SyntheticCoreMutationHost($p);$f=new MutationFence($h);self::assertIsArray($f->register($i));self::assertIsArray($f->reserve($i));$other=new MutationFence(new SyntheticCoreMutationHost($p));self::assertInstanceOf(WP_Error::class,$other->reserve($i));self::assertSame(1,$h->recordCount());}finally{@unlink($p);@unlink($p.'.lock');}}
 public function testLocksRevocationAndEvidenceDenyBeforeDurableReservation():void {foreach(['stop_all','external_lock','revoked','evidence_missing','stale_revision','wrong_scope','wrong_actor','expired','stale_product','audit_unavailable'] as $fault){$i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);try{$h=new SyntheticCoreMutationHost($p);$h->fault=$fault;$f=new MutationFence($h);self::assertInstanceOf(WP_Error::class,$f->reserve($i),$fault);self::assertSame(0,$h->recordCount(),$fault);}finally{@unlink($p);@unlink($p.'.lock');}}}
 public function testFailedAndLostAcknowledgementNeverRearm():void {foreach(['claim_write_failure','lost_claim_ack'] as $fault){$i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);try{$h=new SyntheticCoreMutationHost($p);$f=new MutationFence($h);$f->register($i);$h->fault=$fault;self::assertInstanceOf(WP_Error::class,$f->reserve($i));if($fault==='lost_claim_ack'){$h->fault='';self::assertInstanceOf(WP_Error::class,(new MutationFence(new SyntheticCoreMutationHost($p)))->reserve($i));}}finally{@unlink($p);@unlink($p.'.lock');}}}
 public function testTimeoutRateLimitMalformedAndRevokedReadbackRemainUnknown():void {foreach(['timeout','rate_limit','malformed','revoked_after_claim'] as $fault){$i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);try{$h=new SyntheticCoreMutationHost($p);$f=new MutationFence($h);$f->register($i);$f->reserve($i);$h->fault=$fault;$r=$f->reconcile($i,str_repeat('e',64));self::assertSame('UNKNOWN',$h->state($i));self::assertFalse($r['retry_permitted']??true);self::assertFalse($r['network_request_permitted']??true);self::assertInstanceOf(WP_Error::class,$f->reserve($i));}finally{@unlink($p);@unlink($p.'.lock');}}}
 public function testTrustedSyntheticReceiptReconcilesWithoutOwnershipCertification():void {$i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);try{$h=new SyntheticCoreMutationHost($p);$f=new MutationFence($h);$f->register($i);$f->reserve($i);$r=$f->reconcile($i,str_repeat('e',64));self::assertSame('METADATA_RECONCILED',$r['state']);self::assertSame('synthetic_image',$r['metadata']['id']);self::assertFalse($r['metadata']['ownership_certified']);self::assertInstanceOf(WP_Error::class,$f->reserve($i));}finally{@unlink($p);@unlink($p.'.lock');}}
 public function testProductIntentPreservesVersionAndRejectsDuplicateLogicalProduct():void {
  [$scope,$input,$e]=(require __DIR__.'/../fixtures/printify-connector/stage34/product-fixture.php')();
  $context=['actor_id'=>1,'logical_reference'=>'core-product-1','approval_reference'=>$e['approval_receipt_sha256'],'authorization_revision'=>7,'product_version'=>0,'expected_product_version'=>0];
  $service=new PreparationService();$i=$service->product('PRODUCT_CREATE',$scope,$input,$e,$context);
  $p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);
  try{$host=new SyntheticCoreMutationHost($p);$f=new MutationFence($host);self::assertIsArray($f->register($i));$input['title']='Conflicting new payload';$other=$service->product('PRODUCT_CREATE',$scope,$input,$e,$context);self::assertSame($i->duplicateKey,$other->duplicateKey);self::assertNotSame($i->payloadFingerprint,$other->payloadFingerprint);self::assertInstanceOf(WP_Error::class,$f->register($other));self::assertSame(1,$host->recordCount());}finally{@unlink($p);@unlink($p.'.lock');}
 }
 public function testConcurrentReservationHasOneWinner():void {
  $i=$this->upload();$p=tempnam(sys_get_temp_dir(),'df-ledger-');unlink($p);
  try{$h=new SyntheticCoreMutationHost($p);(new MutationFence($h))->register($i);
   $script=realpath(__DIR__.'/../fixtures/printify-connector/stage34/reserve-worker.php');$processes=[];
   for($n=0;$n<2;$n++){$pipes=[];$processes[]=[$proc=proc_open([PHP_BINARY,$script,$p],[1=>['pipe','w'],2=>['pipe','w']],$pipes),$pipes];self::assertIsResource($proc);}
   $wins=0;foreach($processes as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($proc),$err);$wins+=(int)$out;}self::assertSame(1,$wins);self::assertSame('FENCED',$h->state($i));
  }finally{@unlink($p);@unlink($p.'.lock');}
 }

}
