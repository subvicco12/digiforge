<?php
declare(strict_types=1);
use DigiForge\POD\{OfflineCutoutPipeline,RasterArtworkCodec};
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../includes/POD/RasterArtworkCodec.php';
if(is_file(__DIR__.'/../../includes/POD/OfflineCutoutPipeline.php'))require_once __DIR__.'/../../includes/POD/OfflineCutoutPipeline.php';
final class OfflineCutoutPipelineTest extends TestCase
{
 public function testPipelineRequiresExplicitSubjectAndNeverCertifiesSemanticHead():void {
  self::assertTrue(class_exists(OfflineCutoutPipeline::class),'Offline pipeline is not implemented');
  $rgba=str_repeat("\xf0\xf0\xf0\xff",4096);for($y=20;$y<44;$y++)for($x=20;$x<44;$x++){$i=($y*64+$x)*4;$rgba[$i]="\x80";$rgba[$i+1]="\x30";$rgba[$i+2]="\x10";}
  $selection=['subjects'=>[['x'=>8,'y'=>8,'width'=>48,'height'=>48]],'selected'=>0,'foreground_points'=>[['x'=>31,'y'=>31]]];
  $png=RasterArtworkCodec::encode(64,64,$rgba);$p=OfflineCutoutPipeline::segment($png,$selection);self::assertFalse($p['provenance']['semantic_head_certified']);self::assertTrue($p['provenance']['review_required']);self::assertSame($p,OfflineCutoutPipeline::segment($png,$selection));$m=RasterArtworkCodec::decode($p['mask_png']);self::assertSame(255,ord($m['rgba'][(31*64+31)*4+3]));self::assertSame(0,ord($m['rgba'][3]));self::assertSame(hash('sha256',$png),$p['provenance']['source_sha256']);
 }
 public function testMissingRuntimeFailsClosedWithoutCallerMaskFallback():void {
  self::assertTrue(class_exists(OfflineCutoutPipeline::class),'Offline pipeline is not implemented');$old=getenv('DIGIFORGE_CUTOUT_PYTHON');putenv('DIGIFORGE_CUTOUT_PYTHON=/nonexistent/digiforge-python');try{$this->expectException(InvalidArgumentException::class);$this->expectExceptionMessage('Offline processor not configured');OfflineCutoutPipeline::segment(RasterArtworkCodec::encode(8,8,str_repeat("\xff",256)),['subjects'=>[['x'=>1,'y'=>1,'width'=>5,'height'=>5]],'selected'=>0,'foreground_points'=>[['x'=>3,'y'=>3]]]);}finally{$old===false?putenv('DIGIFORGE_CUTOUT_PYTHON'):putenv('DIGIFORGE_CUTOUT_PYTHON='.$old);}
 }
 public function testCallerCannotSelectCommandModelOrUnknownOptions():void {
  self::assertTrue(class_exists(OfflineCutoutPipeline::class),'Offline pipeline is not implemented');$this->expectException(InvalidArgumentException::class);OfflineCutoutPipeline::segment(RasterArtworkCodec::encode(8,8,str_repeat("\xff",256)),['command'=>'bad']);
 }
}
