<?php
declare(strict_types=1);
use DigiForge\POD\{ExperimentalMattingPipeline,OfflineCutoutPipeline,RasterArtworkCodec};
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/RasterArtworkCodec.php';
require_once __DIR__.'/../../includes/POD/OfflineCutoutPipeline.php';
if(is_file(__DIR__.'/../../includes/POD/ExperimentalMattingPipeline.php'))require_once __DIR__.'/../../includes/POD/ExperimentalMattingPipeline.php';
final class ExperimentalMattingPipelineTest extends TestCase
{
 private function selection():array {return ['subjects'=>[['x'=>130,'y'=>2,'width'=>190,'height'=>220]],'selected'=>0,'foreground_points'=>[['x'=>222,'y'=>110],['x'=>222,'y'=>45]],'trimap_width'=>8];}
 public function testCapabilityNeverClaimsCertificationOrUnpromptedExtraction():void {
  self::assertTrue(class_exists(ExperimentalMattingPipeline::class),'Experimental adapter missing');$c=ExperimentalMattingPipeline::capabilities();self::assertTrue($c['experimental']);self::assertTrue($c['review_required']);self::assertFalse($c['automatic_subject_detection']);self::assertFalse($c['commercial_certified']);
 }
 public function testUnreviewedTrimapWidthFailsClosed():void {
  self::assertTrue(class_exists(ExperimentalMattingPipeline::class),'Experimental adapter missing');$s=$this->selection();$s['trimap_width']=24;$this->expectException(InvalidArgumentException::class);ExperimentalMattingPipeline::selection($s,512,512);
 }
 public function testCallerModelOrExecutableIsRejected():void {
  self::assertTrue(class_exists(ExperimentalMattingPipeline::class),'Experimental adapter missing');$this->expectException(InvalidArgumentException::class);ExperimentalMattingPipeline::selection($this->selection()+['model'=>'untrusted'],512,512);
 }
 public function testUnavailableRuntimeCannotReturnSyntheticMask():void {
  self::assertTrue(class_exists(ExperimentalMattingPipeline::class),'Experimental adapter missing');$old=getenv('DIGIFORGE_SAM_PYTHON');putenv('DIGIFORGE_SAM_PYTHON');try{$this->expectException(InvalidArgumentException::class);$this->expectExceptionMessage('Experimental runtime unavailable');ExperimentalMattingPipeline::segment(RasterArtworkCodec::encode(512,512,str_repeat("\xff",1048576)),$this->selection());}finally{$old===false?putenv('DIGIFORGE_SAM_PYTHON'):putenv('DIGIFORGE_SAM_PYTHON='.$old);}
 }
 public function testHostCapacityRejectsConcurrentInferenceAndReleasesLease():void {
  $method=new ReflectionMethod(ExperimentalMattingPipeline::class,'capacity');$first=$method->invoke(null);try{try{$method->invoke(null);self::fail('Concurrent inference accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('processor busy',$e->getMessage());}}finally{fclose($first);}$again=$method->invoke(null);self::assertIsResource($again);fclose($again);
 }
 public function testRealHeadReturnsSoftAlphaImmutableProvenanceAndRejectsClippedCat():void {
  self::assertTrue(class_exists(ExperimentalMattingPipeline::class),'Experimental adapter missing');if(!getenv('DIGIFORGE_SAM_PYTHON'))$this->markTestSkipped('Pinned owner-supplied weights/runtime absent; boundary tests still run.');
  $source=OfflineCutoutPipeline::normalizePhoto(file_get_contents(__DIR__.'/../fixtures/segmentation/astronaut-srgb.png'))['png'];$r=ExperimentalMattingPipeline::segment($source,$this->selection());self::assertTrue($r['provenance']['review_required']);self::assertFalse($r['provenance']['semantic_head_certified']);self::assertGreaterThan(0,$r['provenance']['soft_alpha_pixels']);self::assertSame(hash('sha256',$source),$r['provenance']['original_sha256']);self::assertSame($r,ExperimentalMattingPipeline::segment($source,$this->selection()));
  $s=['subjects'=>[['x'=>15,'y'=>5,'width'=>420,'height'=>285]],'selected'=>0,'foreground_points'=>[['x'=>220,'y'=>145]],'trimap_width'=>8];$this->expectException(InvalidArgumentException::class);ExperimentalMattingPipeline::segment(OfflineCutoutPipeline::normalizePhoto(file_get_contents(__DIR__.'/../fixtures/segmentation/chelsea-srgb.png'))['png'],$s);
 }
}
