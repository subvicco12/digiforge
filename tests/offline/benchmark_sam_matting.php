<?php
/** Opt-in real inference harness; never commercial acceptance or model download. */
declare(strict_types=1);
use DigiForge\POD\{ExperimentalMattingPipeline,OfflineCutoutPipeline};
$root=dirname(__DIR__,2);require $root.'/includes/POD/RasterArtworkCodec.php';require $root.'/includes/POD/OfflineCutoutPipeline.php';require $root.'/includes/POD/ExperimentalMattingPipeline.php';
if($argc!==2||!is_dir($argv[1]))throw new InvalidArgumentException('Existing private benchmark output directory required.');
$selection=['subjects'=>[['x'=>130,'y'=>2,'width'=>190,'height'=>220]],'selected'=>0,'foreground_points'=>[['x'=>222,'y'=>110],['x'=>222,'y'=>45]],'trimap_width'=>8];$records=[];$previous=null;
foreach(['human-cold-process','human-warm-filesystem','clipped-cat','unsupported-width','incomplete-jit-cache']as $case){
 $s=$selection;$file='astronaut-srgb.png';if($case==='clipped-cat'){$file='chelsea-srgb.png';$s=['subjects'=>[['x'=>15,'y'=>5,'width'=>420,'height'=>285]],'selected'=>0,'foreground_points'=>[['x'=>220,'y'=>145]],'trimap_width'=>8];}if($case==='unsupported-width')$s['trimap_width']=24;
 $source=OfflineCutoutPipeline::normalizePhoto(file_get_contents($root.'/tests/fixtures/segmentation/'.$file))['png'];$cache=getenv('DIGIFORGE_SAM_CACHE');$temp=null;
 if($case==='incomplete-jit-cache'){$temp=$argv[1].'/incomplete-cache';mkdir($temp,0700);$manifest=json_decode(file_get_contents($cache.'/manifest.json'),true);$relative=array_key_first($manifest['files']);mkdir(dirname($temp.'/'.$relative),0700,true);copy($cache.'/'.$relative,$temp.'/'.$relative);file_put_contents($temp.'/manifest.json',json_encode(['schema'=>1,'files'=>[$relative=>$manifest['files'][$relative]]]));putenv('DIGIFORGE_SAM_CACHE='.$temp);}
 $start=hrtime(true);$row=['case'=>$case,'input_sha256'=>hash('sha256',$source),'selection'=>$s,'reviewed_accuracy'=>null,'reference_mask_status'=>'MISSING_REVIEWED_ALPHA','commercial_certified'=>false,'supplier_certified'=>false];
 try{$r=ExperimentalMattingPipeline::segment($source,$s);$row+=['status'=>'REVIEW_REQUIRED','provenance'=>$r['provenance']];if($case==='human-warm-filesystem')$row['exact_replay']=$r===$previous;$previous=$r;file_put_contents($argv[1].'/'.$case.'-foreground.png',$r['source_png']);file_put_contents($argv[1].'/'.$case.'-mask.png',$r['mask_png']);}catch(Throwable $e){$row+=['status'=>'REJECTED','reason'=>$e->getMessage()];}
 finally{if($cache!==false)putenv('DIGIFORGE_SAM_CACHE='.$cache);if($temp!==null){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($temp);}}
 $row['wall_seconds']=(hrtime(true)-$start)/1e9;$records[]=$row;echo json_encode($row,JSON_THROW_ON_ERROR)."\n";
}
file_put_contents($argv[1].'/report.json',json_encode(['version'=>ExperimentalMattingPipeline::VERSION,'cold_process_note'=>'Every request starts a new sandbox/model; warm means filesystem cache, not a resident model. JIT precompilation is deployment preparation outside request bounds.','cases'=>$records],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");

if($records[0]["status"]!=="REVIEW_REQUIRED"||$records[1]["status"]!=="REVIEW_REQUIRED"||($records[1]["exact_replay"]??false)!==true||array_filter(array_slice($records,2),static fn(array $r):bool=>$r["status"]!=="REJECTED"))exit(1);
