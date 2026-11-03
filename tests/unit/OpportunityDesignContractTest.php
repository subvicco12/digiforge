<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\Research\OpportunityDesignContract;
require_once __DIR__.'/../../includes/Research/OpportunityDesignContract.php';
final class OpportunityDesignContractTest extends TestCase
{
 public static function fixture():array {
  return ['shop_key'=>'personalized_pod','title'=>'Family Christmas mug','sources'=>[['url'=>'https://example.org/listing','observed_at'=>'2026-11-03T12:00:00Z','excerpt'=>'Observed family name personalization; sales volume unverified.']], 'briefs'=>array_map(static fn($n)=>['audience'=>'Audience '.$n,'visual_direction'=>'Original direction '.$n,'rationale'=>'Evidence supports gift use '.$n],range(1,3)), 'artwork'=>['svg'=>'<svg xmlns="http://www.w3.org/2000/svg" width="2475" height="1155" viewBox="0 0 2475 1155"><rect width="2475" height="1155" fill="#ffffff"/></svg>','width_px'=>2475,'height_px'=>1155,'dpi'=>300,'selected_brief'=>1,'native_layers'=>['family_name'],'rights_note'=>'Original artwork; no external fonts','safe_area_reviewed'=>true,'font_license_reviewed'=>true,'mockup_reviewed'=>true]];
 }
 public function testPreservesEvidenceAndEditableArtwork():void {
  $in=self::fixture();$out=OpportunityDesignContract::normalize($in);
  self::assertSame($in,$out);self::assertSame($in['sources'][0]['observed_at'],$out['sources'][0]['observed_at']);
 }
 /** @dataProvider invalidInputs */
 public function testRejectsUnsafeOrIncompleteEvidence(array $input):void {
  $this->expectException(\InvalidArgumentException::class);OpportunityDesignContract::normalize($input);
 }
 public static function invalidInputs():array {
  $base=self::fixture();$cases=[];
  foreach(['digital','standard_pod','all','jewelry']as $shop){$x=$base;$x['shop_key']=$shop;$cases[]=[$x];}
  $x=$base;$x['sources']=[];$cases[]=[$x];
  $x=$base;$x['sources'][0]['observed_at']='yesterday';$cases[]=[$x];
  $x=$base;$x['sources'][0]['url']='file:///etc/passwd';$cases[]=[$x];
  $x=$base;$x['briefs'][2]=$x['briefs'][0];$cases[]=[$x];
  foreach(['<script>alert(1)</script>','<image href="https://example.org/a"/>','<rect onload="alert(1)"/>','<style>rect{fill:url(https://example.org/a)}</style>']as $svg){$x=$base;$x['artwork']['svg']='<svg xmlns="http://www.w3.org/2000/svg" width="2475" height="1155">'.$svg.'</svg>';$cases[]=[$x];}
  $x=$base;$x['artwork']['dpi']=72;$cases[]=[$x];
  $x=$base;$x['artwork']['width_px']=1;$cases[]=[$x];
  $x=$base;$x['artwork']['safe_area_reviewed']=false;$cases[]=[$x];
  $x=$base;$x['approved_by']=99;$cases[]=[$x];
  $x=$base;$x['artwork']['svg']='';$cases[]=[$x];
  return $cases;
 }
}
