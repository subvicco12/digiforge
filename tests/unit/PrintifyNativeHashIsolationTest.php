<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Connector/Printify/NativePortContract.php';
use DigiForge\Connector\Printify\NativePortContract;
use PHPUnit\Framework\TestCase;
final class PrintifyNativeHashIsolationTest extends TestCase
{
 public function testCanonicalHashDoesNotReorderCallerOwnedReferencedMaps():void
 {
  $body=['z'=>1,'a'=>2];$input=['body'=>&$body];self::assertSame(hash('sha256','{"body":{"a":2,"z":1}}'),NativePortContract::hash($input));self::assertSame(['z','a'],array_keys($body),'Canonical hashing must not mutate an approved caller payload.');self::assertSame(['z','a'],array_keys($input['body']));
 }
 public function testUntrustedHashValuesCannotRunSerializationCallbacks():void
 {
  $called=false;$object=new class($called) implements JsonSerializable{private mixed $flag;public function __construct(bool &$called){$this->flag=&$called;}public function jsonSerialize():mixed{$this->flag=true;return ['value'=>'changed'];}};
  try{NativePortContract::hash(['data'=>$object]);self::fail('Non-JSON data must be rejected.');}catch(InvalidArgumentException){self::assertFalse($called);}
 }
}
