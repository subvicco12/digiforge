<?php
declare(strict_types=1);
/** Recompute QA on hash-pinned benchmark predictions; these are NOT reference mattes. */
use DigiForge\POD\{CutoutVisualQa,CutoutStyleAdapter};
$root=dirname(__DIR__);foreach(['RasterArtworkCodec','CutoutStyleAdapter','CutoutVisualQa']as $class)require $root.'/includes/POD/'.$class.'.php';
if($argc!==3||!is_dir($argv[1])||!is_dir($argv[2]))throw new InvalidArgumentException('Existing private input/output directories required.');
$benchmark=json_decode(file_get_contents($root.'/docs/engineering/sam-small-matting-benchmark.json'),true,512,JSON_THROW_ON_ERROR);$reports=[];
foreach(array_slice($benchmark['cases'],0,2)as $case){$stem=$case['case'];$pngs=[];foreach(['foreground','mask']as $kind){$path=$argv[1].'/'.$stem.'-'.$kind.'.png';if(is_link($path)||!is_file($path)||filesize($path)>16777216)throw new InvalidArgumentException('Bounded regular prediction required.');$pngs[$kind]=file_get_contents($path);$expected=$case['provenance'][$kind==='mask'?'mask_sha256':'source_sha256'];if(!hash_equals($expected,hash('sha256',$pngs[$kind])))throw new InvalidArgumentException('Prediction differs from audited benchmark receipt.');}
 $styled=CutoutStyleAdapter::apply($pngs['foreground'],$pngs['mask'],'CS01',[]);$previews=CutoutVisualQa::previews($styled);$qa=['case'=>$stem,'mask'=>CutoutVisualQa::measure($pngs['mask']),'styled'=>CutoutVisualQa::measure($styled),'preview_sha256'=>array_map(static fn(string $bytes):string=>hash('sha256',$bytes),$previews),'model_sha256'=>$case['provenance']['model_sha256']];
 foreach($previews as $bg=>$bytes)if(file_put_contents($argv[2].'/'.$stem.'-'.$bg.'.png',$bytes)!==strlen($bytes))throw new RuntimeException('Preview write failed.');$reports[]=$qa;
}
$report=['schema'=>1,'version'=>CutoutVisualQa::VERSION,'scope'=>'OFFLINE_SOFTWARE_TEST_ONLY','distinct_real_subjects'=>1,'reviewed_reference_mattes'=>0,'accuracy'=>'NOT_MEASURABLE_WITHOUT_REVIEWED_REFERENCE','commercial_certified'=>false,'supplier_certified'=>false,'cases'=>$reports];
if(file_put_contents($argv[2].'/visual-qa-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n")===false)throw new RuntimeException('Report write failed.');
