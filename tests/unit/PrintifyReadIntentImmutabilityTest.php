<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/POD/PrintifyConnectorReadContract.php';
require_once __DIR__.'/../../includes/Connector/Printify/ExternalOperationIntent.php';
use DigiForge\Connector\Printify\ExternalOperationIntent;
use PHPUnit\Framework\TestCase;
final class PrintifyReadIntentImmutabilityTest extends TestCase
{
 public function testCallerReferencesCannotChangeProductSelection():void
 {
  $product='product_a';$input=['product_id'=>&$product];$intent=new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCT_READ',$input,str_repeat('a',32));$product='product_b';self::assertSame(['product_id'=>'product_a'],$intent->input);self::assertSame(['product_id'=>'product_b'],$input);
 }
 public function testCallerReferencesCannotChangePagination():void
 {
  $page=1;$limit=10;$input=['limit'=>&$limit,'page'=>&$page];$intent=new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCTS_READ',$input,str_repeat('a',32));self::assertSame('{"actorId":1,"shopKey":"personalized_pod","integrationId":3,"operation":"PRODUCTS_READ","input":{"limit":10,"page":1},"intentId":"'.str_repeat('a',32).'","sourceReferences":[]}',json_encode($intent));$page=3;$limit=1;self::assertSame(['limit'=>10,'page'=>1],$intent->input);
 }
 public function testCallerReferencesCannotReplaceOwnedUploadReceipts():void
 {
  $upload='image_a';$receipt=str_repeat('b',64);$input=['upload_id'=>&$upload];$refs=[&$receipt];$intent=new ExternalOperationIntent(1,'personalized_pod',3,'UPLOAD_READ',$input,str_repeat('a',32),$refs);$upload='image_b';$receipt=str_repeat('c',64);self::assertSame(['upload_id'=>'image_a'],$intent->input);self::assertSame([str_repeat('b',64)],$intent->sourceReferences);
 }
 /** @dataProvider callbackFields */
 public function testMalformedInputCannotExecuteJsonSerializationCallbacks(string $key,bool $nested):void
 {
  $called=false;$object=new class($called) implements JsonSerializable {private mixed $flag;public function __construct(bool &$called){$this->flag=&$called;}public function jsonSerialize():mixed{$this->flag=true;return 'product_a';}};
  try {new ExternalOperationIntent(1,'personalized_pod',3,'PRODUCT_READ',[$key=>$nested?[$object]:$object],str_repeat('a',32));self::fail('Malformed read input must be rejected.');}catch(InvalidArgumentException){self::assertFalse($called,'Reject invalid types before JSON encoding can invoke caller code.');}
 }
 public static function callbackFields():array{return ['known'=>['product_id',false],'unknown'=>['not_allowed',false],'nested'=>['product_id',true]];}
 public function testDistinctNumericLookingReceiptHashesRemainDistinct():void
 {
  $refs=['0e'.str_repeat('1',62),'0e'.str_repeat('2',62)];try{$intent=new ExternalOperationIntent(1,'personalized_pod',3,'UPLOADS_READ',[],str_repeat('a',32),$refs);}catch(InvalidArgumentException){self::fail('Distinct receipt SHA-256 values must not compare as numeric zero.');}self::assertSame($refs,$intent->sourceReferences);
 }
 public function testAnExactlyDuplicatedReceiptRemainsDenied():void
 {
  $this->expectException(InvalidArgumentException::class);new ExternalOperationIntent(1,'personalized_pod',3,'UPLOADS_READ',[],str_repeat('a',32),[str_repeat('b',64),str_repeat('b',64)]);
 }

}
