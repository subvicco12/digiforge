<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PrintifyApiApprovalFixture.php';
use DigiForge\POD\{PrintifyApiReadWorkflow,PrintifyLifecycleWorkflow,PrintifyControlledTransport};
final class PrintifyLineCancellationExecutionTest extends WP_UnitTestCase
{
 private PrintifyApiApprovalFixture $fixture;
 public static function set_up_before_class():void{parent::set_up_before_class();PrintifyApiApprovalFixture::checked(DigiForge\Database\ProviderConnectionSchema::migrateIfNeeded());}
 protected function setUp():void{parent::setUp();$this->fixture=new PrintifyApiApprovalFixture();$this->fixture->boot();}
 protected function tearDown():void{try{$this->fixture->restore();}finally{parent::tearDown();}}
 private function read(string $state,array $lines):array{
  $w=new PrintifyApiReadWorkflow();$p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'ORDER_READ',['order_id'=>'o1']));$a=$this->fixture->approve($w,$p,'printify-api-read');return PrintifyApiApprovalFixture::checked((new PrintifyControlledTransport(static fn()=>PrintifyApiApprovalFixture::response(['id'=>'o1','status'=>$state,'line_items'=>$lines])))->executeApiRead($p['hash'],$a['hash']));
 }
 public function testExactLine204ConsumesAuthorizationAndReconcilesOnlyMatchingCanceledLine():void{
  $line=['id'=>'l1','product_id'=>'p1','variant_id'=>3,'quantity'=>1,'status'=>'pending'];$read=$this->read('pending',[$line]);$w=new PrintifyLifecycleWorkflow();$input=['order_id'=>'o1','line_item_id'=>'l1'];
  self::assertWPError($w->prepare('personalized_pod',$this->fixture->integration,'ORDER_CANCEL',['order_id'=>'o1'],$read['hash']));
  self::assertWPError($w->prepare('personalized_pod',$this->fixture->integration,'LINE_ITEM_CANCEL',['order_id'=>'o1','line_item_id'=>'missing'],$read['hash']));
  $p=PrintifyApiApprovalFixture::checked($w->prepare('personalized_pod',$this->fixture->integration,'LINE_ITEM_CANCEL',$input,$read['hash']));self::assertWPError($w->authorize($p['hash']));$a=$this->fixture->approve($w,$p,'printify-lifecycle');$calls=0;
  $t=new PrintifyControlledTransport(static function($url,$args)use(&$calls){++$calls;self::assertSame('https://api.printify.com/v1/shops/12/orders/o1/line_items/l1/cancel.json',$url);self::assertSame('POST',$args['method']);self::assertArrayNotHasKey('body',$args);return ['response'=>['code'=>204],'headers'=>[],'body'=>''];});
  $result=PrintifyApiApprovalFixture::checked($t->executeLifecycle($p['hash'],$a['hash']));self::assertSame('SUCCEEDED',$result['record']['status']);self::assertFalse($result['record']['data']['cancellation_confirmed']);self::assertWPError($t->executeLifecycle($p['hash'],$a['hash']));self::assertSame(1,$calls);
  $line['status']='canceled';$line['id']='other';$other=$this->read('canceled',[$line]);$r=PrintifyApiApprovalFixture::checked($w->reconcile($result['hash'],$other['hash']));self::assertSame('NOT_CONFIRMED',$r['record']['disposition']);
  $line['id']='l1';$exact=$this->read('canceled',[$line]);$r=PrintifyApiApprovalFixture::checked($w->reconcile($result['hash'],$exact['hash']));self::assertSame('CONFIRMED_BY_READBACK',$r['record']['disposition']);self::assertFalse($r['record']['retry_authorized']);
 }
}
