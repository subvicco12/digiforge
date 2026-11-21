<?php
declare(strict_types=1);
use DigiForge\POD\PrintifyWebhookEvidence;
use DigiForge\POD\PrintifyWebhookRequest;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/PrintifyWebhookEvidence.php';
require_once __DIR__.'/../../includes/POD/PrintifyWebhookRequest.php';
final class PrintifyWebhookLifecycleTest extends TestCase {
 private function raw(string $shop='123',int $age=0):string{return json_encode(['id'=>'12345678-1234-1234-1234-123456789abc','type'=>'order:created','created_at'=>gmdate('Y-m-d H:i:s+00:00',time()-$age),'resource'=>['id'=>'order123','type'=>'order','data'=>['shop_id'=>$shop]]],JSON_THROW_ON_ERROR);}
 public function testSignatureAuthenticatesExactRawBodyAndProjectsOnlyReferences():void{$raw=$this->raw();$key=str_repeat('a',32);$r=PrintifyWebhookEvidence::verify($raw,'sha256='.hash_hmac('sha256',$raw,$key),$key,'123',time());self::assertSame('order123',$r['resource_id']);self::assertArrayNotHasKey('data',$r);self::assertFalse($r['business_transition_authorized']);self::assertSame('DUPLICATE_NO_TRANSITION',PrintifyWebhookEvidence::replayDisposition($r,$r));$other=$r;$other['payload_sha256']=str_repeat('b',64);self::assertSame('CONFLICT_REJECT',PrintifyWebhookEvidence::replayDisposition($r,$other));}
 public function testInvalidSignatureRejected():void{$this->expectException(InvalidArgumentException::class);PrintifyWebhookEvidence::verify($this->raw(),'sha256='.str_repeat('0',64),str_repeat('a',32),'123',time());}
 public function testCrossShopRejected():void{$raw=$this->raw('456');$key=str_repeat('a',32);$this->expectException(InvalidArgumentException::class);PrintifyWebhookEvidence::verify($raw,'sha256='.hash_hmac('sha256',$raw,$key),$key,'123',time());}
 public function testStaleSignedReplayRejected():void{$raw=$this->raw('123',86401);$key=str_repeat('a',32);$this->expectException(InvalidArgumentException::class);PrintifyWebhookEvidence::verify($raw,'sha256='.hash_hmac('sha256',$raw,$key),$key,'123',time());}
 public function testReadAndSecretInjectionInputBoundary():void{self::assertSame('GET',PrintifyWebhookRequest::prepare('WEBHOOK_READ','123',[])['method']);$this->expectException(InvalidArgumentException::class);PrintifyWebhookRequest::prepare('WEBHOOK_CREATE','123',['secret'=>'forbidden']);}
}
