<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\{PrintifyControlledTransport,PrintifyLifecycleRequest,PrintifyLifecycleEvidence,PrintifyOrderEvidence};
foreach(['PrintifyControlledTransport','PrintifyLifecycleRequest','PrintifyLifecycleEvidence','PrintifyOrderEvidence'] as $class)require_once __DIR__.'/../../includes/POD/'.$class.'.php';
final class PrintifyOrderAckLineCancellationTest extends TestCase
{
 public function testIdOnlyAcknowledgementsAreValidForCreateAndMatchingProduction():void{
  self::assertSame('o1',PrintifyControlledTransport::acknowledgedId('{"id":"o1"}'));
  self::assertSame('o1',PrintifyControlledTransport::acknowledgedId('{"id":"o1"}','o1'));
 }
 public function testMalformedAndMismatchedProductionAcknowledgementsAreRejected():void{
  foreach(['','{','null','[]','{}','{"id":"other"}','{"id":7}',str_repeat(' ',1048577)] as $raw){try{PrintifyControlledTransport::acknowledgedId($raw,'o1');self::fail('Invalid acknowledgement accepted');}catch(\JsonException|\InvalidArgumentException|\LengthException $expected){self::assertTrue(true);}}
 }
 public function testLineCancellationUsesExactProviderLineIdentityAndEmpty204():void{
  $r=PrintifyLifecycleRequest::prepare('LINE_ITEM_CANCEL','12',['order_id'=>'o1','line_item_id'=>'line_1']);
  self::assertSame('/v1/shops/12/orders/o1/line_items/line_1/cancel.json',$r['endpoint']);self::assertSame('POST',$r['method']);self::assertSame([],$r['body']);self::assertSame('orders.write',$r['required_scope']);self::assertFalse($r['external_execution_authorized']);
  $e=PrintifyLifecycleEvidence::emptyCancellation($r,204,'');self::assertSame('line_1',$e['line_item_id']);self::assertFalse($e['cancellation_confirmed']);
  foreach([[200,''],[204,'{}'],[204,' ']] as [$status,$raw]){try{PrintifyLifecycleEvidence::emptyCancellation($r,$status,$raw);self::fail('Invalid cancellation ack accepted');}catch(\InvalidArgumentException $expected){self::assertTrue(true);}}
 }
 public function testLineIdIsOptionalButNeverSynthesizedAndDuplicatesFail():void{
  $line=['product_id'=>'p1','variant_id'=>3,'quantity'=>1,'status'=>'pending'];$o=['id'=>'o1','status'=>'pending','line_items'=>[$line]];
  self::assertArrayNotHasKey('id',PrintifyOrderEvidence::project($o,'o1')['line_items'][0]);$line['id']='l1';$o['line_items']=[$line];self::assertSame('l1',PrintifyOrderEvidence::project($o,'o1')['line_items'][0]['id']);
  $o['line_items'][]=$line;$this->expectException(\InvalidArgumentException::class);PrintifyOrderEvidence::project($o,'o1');
 }
 public function testCancellationRejectsPathInjectionAndExtraFields():void{
  foreach([['order_id'=>'o1','line_item_id'=>'../l'],['order_id'=>'o1'],['order_id'=>'o1','line_item_id'=>'l','retry'=>true]] as $input){try{PrintifyLifecycleRequest::prepare('LINE_ITEM_CANCEL','12',$input);self::fail('Invalid line request accepted');}catch(\InvalidArgumentException $expected){self::assertTrue(true);}}
 }
}
