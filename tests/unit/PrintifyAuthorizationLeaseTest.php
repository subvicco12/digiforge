<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PrintifyAuthorizationLeaseTest extends TestCase
{
 private function valid():array { return ['plan_hash'=>str_repeat('a',64),'actor'=>1,'nonce'=>str_repeat('b',48),'issued_at'=>1000,'expires_at'=>1300]; }
 private function current(array $a,int $now=1000):bool {
  $file=dirname(__DIR__,2).'/includes/POD/PrintifyAuthorizationLease.php';self::assertFileExists($file);require_once $file;
  return DigiForge\POD\PrintifyAuthorizationLease::isCurrent($a,str_repeat('a',64),1,$now);
 }
 public function testExactServerOwnedLeaseIsCurrentOnlyBeforeExpiry():void {
  self::assertTrue($this->current($this->valid()));self::assertTrue($this->current($this->valid(),1299));self::assertFalse($this->current($this->valid(),1300));self::assertFalse($this->current($this->valid(),999));
 }
 public function testMissingTypedAndOverlongLeasesFailClosed():void {
  foreach(['issued_at','expires_at','actor','plan_hash','nonce'] as $field){$a=$this->valid();unset($a[$field]);self::assertFalse($this->current($a));}
  foreach(['issued_at'=>'1000','expires_at'=>1301,'actor'=>'1','plan_hash'=>str_repeat('c',64),'nonce'=>'raw'] as $field=>$value){$a=$this->valid();$a[$field]=$value;self::assertFalse($this->current($a));}
 }
 public function testLeaseIsNotAnAuthorityOrEpochProducer():void {
  $a=$this->valid();$a['independent_checkpoint_verified']=true;self::assertTrue($this->current($a));
  self::assertArrayNotHasKey('recovery_epoch',$a);self::assertFalse($this->current($a,1300));
 }
}
