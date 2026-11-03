<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use DigiForge\Research\CommercialResearchContract as Contract;
use PHPUnit\Framework\TestCase;
$path=__DIR__.'/../../includes/Research/CommercialResearchContract.php';if(is_file($path))require_once $path;
final class CommercialResearchContractTest extends TestCase
{
 protected function setUp():void {self::assertTrue(class_exists(Contract::class),'Commercial research contract must be implemented');}
 private function fixture():array {return json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);}
 public function testGroundedConceptsAndQualitativeScoring():void {
  $f=$this->fixture();$packet=Contract::research($f['research']);$out=Contract::response(json_encode($f['response']),$packet);
  self::assertCount(3,$out['concepts']);self::assertSame(49.0,$out['score']['value']);self::assertSame('QUALITATIVE_REVIEW_HEURISTIC',$out['score']['basis']);
  $prompt=Contract::designPrompt($packet,$out['concepts'][0]);self::assertStringContainsString('Two interlocking constellation rings',$prompt);self::assertStringContainsString('Midnight navy',$prompt);self::assertStringContainsString('NOT finished artwork',$prompt);
  self::assertStringContainsString('UNTRUSTED SOURCE DATA',Contract::researchPrompt($packet));
 }
 public function testMissingTrendDoesNotInventACompleteScore():void {
  $f=$this->fixture();$f['research']['sources']=array_slice($f['research']['sources'],0,2);$f['response']['scores']['seasonal_urgency']=null;$f['response']['scores']['demand']['source_ids']=['b1'];$f['response']['concepts'][2]['source_ids']=['b1'];
  $out=Contract::response(json_encode($f['response']),Contract::research($f['research']));self::assertNull($out['score']['value']);self::assertContains('seasonal_urgency',$out['score']['missing']);
 }
 public function testUnknownAiSourcesAndInjectedAuthorityAreRejected():void {
  $f=$this->fixture();$f['response']['concepts'][0]['source_ids']=['invented'];$this->expectException(\InvalidArgumentException::class);Contract::response(json_encode($f['response']),$f['research']);
 }
 public function testDuplicateDirectionsAreRejected():void {
  $f=$this->fixture();$f['response']['concepts'][1]=$f['response']['concepts'][0];$this->expectException(\InvalidArgumentException::class);Contract::response(json_encode($f['response']),$f['research']);
 }
 /** @dataProvider badResponses */
 public function testMalformedAdversarialResponseFailsClosed(string $raw):void {$this->expectException(\InvalidArgumentException::class);Contract::response($raw,$this->fixture()['research']);}
 public static function badResponses():array {return [['not json'],['```json {} ```'],['[]'],['{"concepts":[],"scores":{},"approved_by":1}'],[str_repeat('x',100001)],['{"scores":null,"concepts":null}']];}
 public function testCrossShopAndCredentialUrlsRejected():void {$f=$this->fixture();$f['research']['shop_key']='digital';$this->expectException(\InvalidArgumentException::class);Contract::research($f['research']);}
 public function testSourceProvenanceCannotBeOmitted():void {$f=$this->fixture();unset($f['research']['sources'][0]['provenance']);$this->expectException(\InvalidArgumentException::class);Contract::research($f['research']);}
}
