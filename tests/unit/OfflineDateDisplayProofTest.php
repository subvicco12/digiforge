<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\POD\OfflineDateDisplayProof as Policy;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/LocalPersonalizationProofTest.php';
require_once __DIR__.'/../../includes/POD/PersonalizationPayloadContract.php';
require_once __DIR__.'/../../includes/Research/ArtworkPersonalizationContract.php';
if(is_file(__DIR__.'/../../includes/POD/OfflineDateDisplayProof.php'))require_once __DIR__.'/../../includes/POD/OfflineDateDisplayProof.php';
final class OfflineDateDisplayProofTest extends TestCase
{
 public function testApprovedDatePolicyIsImplemented():void {self::assertTrue(class_exists(Policy::class),'Versioned offline date policy missing');self::assertSame('November 15, 2026',Policy::printedDate('2026-11-15'));}
 /** @dataProvider invalidDates */
 public function testRejectsAmbiguousOrInvalidDates(string $value):void {self::assertTrue(class_exists(Policy::class));$this->expectException(\InvalidArgumentException::class);Policy::printedDate($value);}
 public static function invalidDates():array{return array_map(fn($v)=>[$v],['2023-02-29','1900-02-29','2024-04-31','2024-13-01','2024-00-01','0000-01-01','2024-1-01','2024-01-1','2024-01-01Z',' 2024-01-01','2024-01-01\n','June 18, 2021']);}
 public function testWholeLeapYearAndBoundariesAreDeterministic():void {
 self::assertTrue(class_exists(Policy::class));$old=date_default_timezone_get();date_default_timezone_set('Pacific/Kiritimati');
 try{for($i=0;$i<366;$i++){$d=(new \DateTimeImmutable('2024-01-01',new \DateTimeZone('UTC')))->modify("+$i days");$text=Policy::printedDate($d->format('Y-m-d'));self::assertSame($d->format('F j, Y'),$text);self::assertLessThanOrEqual(18,strlen($text));}self::assertSame('January 1, 0001',Policy::printedDate('0001-01-01'));self::assertSame('December 31, 9999',Policy::printedDate('9999-12-31'));self::assertSame('February 29, 2000',Policy::printedDate('2000-02-29'));}finally{date_default_timezone_set($old);}}
 public function testCanonicalInputDisplayAndPolicyAreBoundWithoutApproval():void {
 self::assertTrue(class_exists(Policy::class));foreach(['FL-03'=>'fl03_mug_11oz','FL-04'=>'fl04_poster_8x10']as $product=>$profile){$s=LocalPersonalizationProofTest::spec($profile);$s['layout']['native_regions']=[['name'=>'first_name','kind'=>'text','x'=>200,'y'=>100,'width'=>1800,'height'=>150],['name'=>'second_name','kind'=>'text','x'=>200,'y'=>300,'width'=>1800,'height'=>150],['name'=>'date','kind'=>'text','x'=>200,'y'=>500,'width'=>1800,'height'=>110]];$a=LocalPersonalizationProofTest::artwork($s);$input=['first_name'=>'Élodie','second_name'=>'José','date'=>'2026-11-15'];$r=Policy::render($product,$s,$a,$input);self::assertSame($input,$r['canonical_inputs']);self::assertSame('November 15, 2026',$r['proof']['samples']['date']);self::assertSame('MANUAL_REVIEW',$r['proof']['state']);self::assertFalse($r['external_execution_authorized']);self::assertFalse($r['production_file']);self::assertSame($r,Policy::render($product,$s,$a,$input));$input['date']='2026-11-16';self::assertNotSame($r['receipt_sha256'],Policy::render($product,$s,$a,$input)['receipt_sha256']);$this->expectPolicyDenial($product,$s,$a,$input,'unknown-policy');}}
 public function testGlyphAndLayoutHoldsAreNotBypassed():void {
 $s=LocalPersonalizationProofTest::spec();$s['layout']['native_regions']=[['name'=>'first_name','kind'=>'text','x'=>200,'y'=>100,'width'=>1800,'height'=>150],['name'=>'second_name','kind'=>'text','x'=>200,'y'=>300,'width'=>1800,'height'=>150],['name'=>'date','kind'=>'text','x'=>200,'y'=>500,'width'=>1800,'height'=>110]];
 $art=LocalPersonalizationProofTest::artwork($s);
 foreach(['🐈','A,B'] as $name){$r=Policy::render('FL-03',$s,$art,['first_name'=>$name,'second_name'=>'Alex','date'=>'2026-11-15']);self::assertSame('MANUAL_HOLD',$r['proof']['state']);self::assertNull($r['proof']['svg']);}
 $s['layout']['native_regions'][2]['width']=100;
 $r=Policy::render('FL-03',$s,$art,['first_name'=>'Maya','second_name'=>'Alex','date'=>'2026-11-15']);self::assertSame('MANUAL_HOLD',$r['proof']['state']);self::assertNull($r['proof']['svg']);
 }
 private function expectPolicyDenial($product,$s,$a,$input,$policy):void{try{Policy::render($product,$s,$a,$input,$policy);self::fail('Unknown policy accepted');}catch(\InvalidArgumentException){self::assertTrue(true);}}
}
