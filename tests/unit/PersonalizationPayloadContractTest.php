<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PersonalizationPayloadContract;
if(is_file(__DIR__.'/../../includes/POD/PersonalizationPayloadContract.php'))require_once __DIR__.'/../../includes/POD/PersonalizationPayloadContract.php';
final class PersonalizationPayloadContractTest extends TestCase
{
 public function testExactTypedValuesPreserveUnicodeAndApprovedSourceReferences():void {
  self::assertTrue(class_exists(PersonalizationPayloadContract::class));$fields=[['key'=>'name','type'=>'text','required'=>true,'min_length'=>1,'max_length'=>2],['key'=>'color','type'=>'choice','choices'=>['blue','red']],['key'=>'day','type'=>'date'],['key'=>'count','type'=>'number','minimum'=>1,'maximum'=>3],['key'=>'photo','type'=>'image_reference']];$payload=['name'=>'Åé','color'=>'blue','day'=>'2024-02-29','count'=>2,'photo'=>'digiforge/product-factory/render-artifacts/fixture.png'];PersonalizationPayloadContract::validate($fields,$payload);self::assertSame('Åé',$payload['name']);
 }
 public function testLegacyZeroMaximumRetainsBoundedUnspecifiedSemantics():void {PersonalizationPayloadContract::validate([['key'=>'name','type'=>'text','max_length'=>0]],['name'=>'Ada']);self::assertTrue(true);}
 public static function invalidPayloads():array{return [
  [[['key'=>'name','type'=>'text']],['extra'=>'Ada']],
  [[['key'=>'name','type'=>'text','required'=>true]],[]],
  [[['key'=>'name','type'=>'text','max_length'=>2]],['name'=>'Ada']],
  [[['key'=>'name','type'=>'text']],['name'=>2]],
  [[['key'=>'color','type'=>'choice','choices'=>['blue']]],['color'=>'red']],
  [[['key'=>'color','type'=>'choice']],['color'=>'blue']],
  [[['key'=>'day','type'=>'date']],['day'=>'2025-02-29']],
  [[['key'=>'count','type'=>'number']],['count'=>'2']],
  [[['key'=>'count','type'=>'number','maximum'=>3]],['count'=>4]],
  [[['key'=>'count','type'=>'number']],['count'=>INF]],
  [[['key'=>'photo','type'=>'image_reference']],['photo'=>'https://example.invalid/a.png']],
  [[['key'=>'photo','type'=>'image_reference']],['photo'=>'digiforge/product-factory/../secret.png']],
  [[['key'=>'name','type'=>'text','pattern'=>'unsafe-regex']],['name'=>'Ada']],
  [[['key'=>'name','type'=>'text','required'=>'false']],[]],
  [[['key'=>'name','type'=>'text'],['key'=>'name','type'=>'number']],['name'=>'Ada']],
  [[['key'=>'name','type'=>'text','required'=>null]],[]],
  [[['key'=>'name','type'=>'text','max_length'=>null]],['name'=>'Ada']],
  [[['key'=>'name','type'=>'text','format'=>null]],['name'=>'Ada']],
  [[['key'=>'count','type'=>'number','max_length'=>2]],['count'=>3]],
  [[['key'=>'name','type'=>'text','answer_key'=>'arbitrary']],['name'=>'Ada']],
  [['name'=>['type'=>'string']],['name'=>'Ada']]
 ];}
 /** @dataProvider invalidPayloads */
 public function testInvalidOrUnsupportedApprovedSchemaCannotCertifyPayload(array $fields,array $payload):void {self::assertTrue(class_exists(PersonalizationPayloadContract::class));$this->expectException(InvalidArgumentException::class);PersonalizationPayloadContract::validate($fields,$payload);}
}
