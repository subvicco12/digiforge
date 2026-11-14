<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\Research\ArtworkPersonalizationContract as Contract;
use DigiForge\Research\DesignFactoryContract;
require_once __DIR__.'/../../includes/Research/CommercialResearchContract.php';
require_once __DIR__.'/../../includes/Research/OpportunityDesignContract.php';
require_once __DIR__.'/../../includes/Research/DesignFactoryContract.php';
require_once __DIR__.'/../../includes/POD/LocalPersonalizationProof.php';
require_once __DIR__.'/../../includes/POD/PersonalizationPayloadContract.php';
if(is_file(__DIR__.'/../../includes/Research/ArtworkPersonalizationContract.php'))require_once __DIR__.'/../../includes/Research/ArtworkPersonalizationContract.php';
final class ArtworkPersonalizationContractTest extends TestCase
{
 public static function spec():array {
  $f=json_decode(file_get_contents(__DIR__.'/../fixtures/research/commercial-research.json'),true);
  return DesignFactoryContract::specification(['research'=>$f['research'],'concept'=>$f['response']['concepts'][0],'selected_concept'=>1,'commercial_hash'=>str_repeat('a',64),'briefs'=>$f['response']['concepts']],'fl03_mug_11oz',['layout_notes'=>'Fixed art separate','typography'=>'Native text unverified','font_license_reference'=>'Synthetic fixture rights only','palette'=>['#123456'],'native_regions'=>[['name'=>'first_name','kind'=>'text','x'=>300,'y'=>200,'width'=>1500,'height'=>150],['name'=>'second_name','kind'=>'text','x'=>300,'y'=>400,'width'=>1500,'height'=>150],['name'=>'date','kind'=>'text','x'=>300,'y'=>600,'width'=>1500,'height'=>150]],'asset_requirements'=>['Editable native fields']]);
 }
 public static function fields():array {return [['key'=>'first_name','type'=>'text','required'=>true,'max_length'=>20],['key'=>'second_name','type'=>'text','required'=>true,'max_length'=>20],['key'=>'date','type'=>'date','required'=>true,'max_length'=>10]];}
 private function proof(array $samples):array {self::assertTrue(class_exists(Contract::class));return Contract::proof(self::spec(),file_get_contents(__DIR__.'/../fixtures/design-factory/mug-original.svg'),self::fields(),$samples);}
 public function testAccentedSamplesProduceHeldSourceBoundProofWithoutChangingFixedArt():void {
  $p=$this->proof(['first_name'=>'Élodie','second_name'=>'José','date'=>'2024-02-29']);self::assertSame('MANUAL_REVIEW',$p['state']);self::assertFalse($p['production_file']);self::assertFalse($p['external_execution_authorized']);self::assertSame(hash_file('sha256',__DIR__.'/../fixtures/design-factory/mug-original.svg'),$p['artwork_sha256']);self::assertStringContainsString('Élodie',$p['svg']);self::assertSame(hash('sha256',$p['svg']),$p['proof_sha256']);
 }
 public function testInvalidCalendarDateRejected():void {self::assertTrue(class_exists(Contract::class));$this->expectException(\InvalidArgumentException::class);$this->proof(['first_name'=>'A','second_name'=>'B','date'=>'2025-02-29']);}
 public function testMissingAndExtraValuesAreRejected():void {self::assertTrue(class_exists(Contract::class));$this->expectException(\InvalidArgumentException::class);$this->proof(['first_name'=>'A','date'=>'2024-02-29']);}
 public function testDeclaredCapsCannotBeExceededByUnicodeInput():void {self::assertTrue(class_exists(Contract::class));$this->expectException(\InvalidArgumentException::class);$this->proof(['first_name'=>str_repeat('é',21),'second_name'=>'B','date'=>'2024-02-29']);}
 public function testUnsupportedGlyphsRemainHoldWithoutInventedRendering():void {$p=$this->proof(['first_name'=>'A😀','second_name'=>'B','date'=>'2024-02-29']);self::assertSame('MANUAL_HOLD',$p['state']);self::assertNull($p['svg']);self::assertContains('GLYPH_COVERAGE_UNVERIFIED',$p['reasons']);}
 public function testSchemaMustMapExactNativeRegions():void {self::assertTrue(class_exists(Contract::class));$f=self::fields();$f[0]['key']='other';$this->expectException(\InvalidArgumentException::class);Contract::fields(self::spec(),$f);}
}
