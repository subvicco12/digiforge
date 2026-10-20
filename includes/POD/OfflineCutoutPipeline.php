<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Fixed local worker; never accepts executables, models, paths or provider settings from customers. */
final class OfflineCutoutPipeline
{
    public const VERSION='selected-grabcut-v1';
    public const METHOD='offline_selected_grabcut';
    public const BACKEND='opencv-grabcut-4.12.0/numpy-2.2.6';

    public static function capabilities():array
    {
        return ['version'=>self::VERSION,'adapter'=>self::BACKEND,'configured'=>is_string(getenv('DIGIFORGE_CUTOUT_PYTHON'))&&getenv('DIGIFORGE_CUTOUT_PYTHON')!=='','dependency_readiness'=>'VALIDATED_ON_EXECUTION','automatic_subject_detection'=>false,'learned_model_weights'=>'NOT_USED','supported_input'=>'BOUNDED_SRGB_PNG_JPEG_NORMALIZED_BEFORE_SELECTION','soft_hair_matting'=>false,'review_required'=>true,'external_execution_authorized'=>false];
    }

    public static function segment(string $source,array $selection):array
    {
        $pixels=RasterArtworkCodec::decode($source);$w=$pixels['width_px'];$h=$pixels['height_px'];self::selection($selection,$w,$h);
        for($i=3;$i<strlen($pixels['rgba']);$i+=4)if(ord($pixels['rgba'][$i])!==255)throw new \InvalidArgumentException('Already-transparent input requires reviewed alpha ingestion.');
        $request=json_encode(['width'=>$w,'height'=>$h,'rgba'=>base64_encode($pixels['rgba']),'selection'=>$selection],JSON_THROW_ON_ERROR);
        $reply=self::worker($request);$keys=['version','backend','alpha','foreground_pixels','components_retained','selection_boundary_contact','semantic_head_certified','review_required','external_execution_authorized'];
        if(count($reply)!==count($keys)||array_diff(array_keys($reply),$keys)||($reply['version']??null)!==self::VERSION||($reply['backend']??null)!==self::BACKEND||($reply['semantic_head_certified']??null)!==false||($reply['review_required']??null)!==true||($reply['external_execution_authorized']??null)!==false||!is_bool($reply['selection_boundary_contact']??null)||!is_int($reply['foreground_pixels']??null)||!is_int($reply['components_retained']??null)||!is_string($reply['alpha']??null))throw new \InvalidArgumentException('Exact bounded worker receipt required.');
        $alpha=base64_decode($reply['alpha'],true);if(!is_string($alpha)||strlen($alpha)!==$w*$h||base64_encode($alpha)!==$reply['alpha'])throw new \InvalidArgumentException('Complete actual alpha pixels required.');
        $rgba='';$visible=0;for($i=0;$i<strlen($alpha);$i++){if($alpha[$i]!=="\0"&&$alpha[$i]!=="\xff")throw new \InvalidArgumentException('Unsupported alpha encoding.');$visible+=ord($alpha[$i])===255?1:0;$rgba.="\0\0\0".$alpha[$i];}
        if($visible<2||$visible!==$reply['foreground_pixels']||$reply['components_retained']<1||$reply['components_retained']>16)throw new \InvalidArgumentException('Nontrivial selected foreground required.');
        $mask=RasterArtworkCodec::encode($w,$h,$rgba);unset($reply['alpha']);
        $proof=$reply+['source_sha256'=>hash('sha256',$source),'mask_sha256'=>hash('sha256',$mask),'worker_sha256'=>hash_file('sha256',__DIR__.'/offline/segment.py'),'selection'=>$selection,'orientation'=>'CANONICAL_PNG_NO_EXIF','metadata'=>'NONE_SUPPORTED','alpha_quality'=>'BINARY_REVIEW_REQUIRED','facial_hair_ears_whiskers'=>'MANUAL_REVIEW_REQUIRED','blur_resolution_print_quality'=>'EXISTING_REVIEW_AND_RENDER_QA_REQUIRED'];
        return ['mask_png'=>$mask,'provenance'=>$proof];
    }

    public static function normalizePhoto(string $bytes):array
    {
        if(strlen($bytes)<1||strlen($bytes)>4500000)throw new \InvalidArgumentException('Bounded photo required.');
        $reply=self::worker(json_encode(['kind'=>'normalize_photo','image_base64'=>base64_encode($bytes)],JSON_THROW_ON_ERROR),6000000);
        $keys=['normalizer_version','input_sha256','input_bytes','input_format','orientation_applied','width_px','height_px','normalized_pixel_sha256','metadata_stripped','rgba'];
        if(count($reply)!==count($keys)||array_diff(array_keys($reply),$keys)||($reply['normalizer_version']??null)!=='pillow-13.0.0/exif-transpose-v1'||($reply['input_sha256']??null)!==hash('sha256',$bytes)||($reply['input_bytes']??null)!==strlen($bytes)||!in_array($reply['input_format']??null,['PNG','JPEG'],true)||!is_int($reply['orientation_applied']??null)||$reply['orientation_applied']<1||$reply['orientation_applied']>8||($reply['metadata_stripped']??null)!==true||!is_int($reply['width_px']??null)||!is_int($reply['height_px']??null)||!is_string($reply['rgba']??null))throw new \InvalidArgumentException('Exact normalization evidence required.');
        RasterArtworkCodec::geometry($reply['width_px'],$reply['height_px']);$rgba=base64_decode($reply['rgba'],true);
        if(!is_string($rgba)||strlen($rgba)!==$reply['width_px']*$reply['height_px']*4||base64_encode($rgba)!==$reply['rgba']||($reply['normalized_pixel_sha256']??null)!==hash('sha256',$rgba))throw new \InvalidArgumentException('Complete normalized pixels required.');
        $png=RasterArtworkCodec::encode($reply['width_px'],$reply['height_px'],$rgba);unset($reply['rgba']);$reply['normalized_sha256']=hash('sha256',$png);return ['png'=>$png,'provenance'=>$reply];
    }

    public static function assertProvenance(array $proof,array $source,array $mask):void
    {
        $expected=['version','backend','foreground_pixels','components_retained','selection_boundary_contact','semantic_head_certified','review_required','external_execution_authorized','source_sha256','mask_sha256','worker_sha256','selection','orientation','metadata','alpha_quality','facial_hair_ears_whiskers','blur_resolution_print_quality'];
        if(isset($proof['normalization'])){
            $expected[]='normalization';$n=$proof['normalization'];$keys=['normalizer_version','input_sha256','input_bytes','input_format','orientation_applied','width_px','height_px','normalized_pixel_sha256','metadata_stripped','normalized_sha256'];
            if(!is_array($n)||count($n)!==count($keys)||array_diff(array_keys($n),$keys)||($n['normalizer_version']??null)!=='pillow-13.0.0/exif-transpose-v1'||!is_string($n['input_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$n['input_sha256'])||!is_int($n['input_bytes']??null)||$n['input_bytes']<1||$n['input_bytes']>4500000||!in_array($n['input_format']??null,['PNG','JPEG'],true)||!is_int($n['orientation_applied']??null)||$n['orientation_applied']<1||$n['orientation_applied']>8||($n['normalized_sha256']??null)!==$source['sha256']||($n['normalized_pixel_sha256']??null)!==$source['pixel_sha256']||($n['width_px']??null)!==$source['width_px']||($n['height_px']??null)!==$source['height_px']||($n['metadata_stripped']??null)!==true)throw new \InvalidArgumentException('Stored normalization provenance changed.');
        }
        if(count($proof)!==count($expected)||array_diff(array_keys($proof),$expected)||($proof['version']??null)!==self::VERSION||($proof['backend']??null)!==self::BACKEND||($proof['semantic_head_certified']??null)!==false||($proof['review_required']??null)!==true||($proof['external_execution_authorized']??null)!==false||($proof['source_sha256']??null)!==$source['sha256']||($proof['mask_sha256']??null)!==$mask['sha256']||($proof['worker_sha256']??null)!==hash_file('sha256',__DIR__.'/offline/segment.py')||!is_array($proof['selection']??null))throw new \InvalidArgumentException('Stored segmentation provenance changed.');
        self::selection($proof['selection'],$source['width_px'],$source['height_px']);
    }

    public static function selection(array $s,int $w,int $h):void
    {
        if($w<8||$h<8||count($s)!==3||array_diff(array_keys($s),['subjects','selected','foreground_points'])||!is_array($s['subjects']??null)||!array_is_list($s['subjects'])||count($s['subjects'])<1||count($s['subjects'])>8||!is_int($s['selected']??null)||!isset($s['subjects'][$s['selected']])||!is_array($s['foreground_points']??null)||!array_is_list($s['foreground_points'])||count($s['foreground_points'])<1||count($s['foreground_points'])>16)throw new \InvalidArgumentException('Explicit bounded subject selection required.');
        foreach($s['subjects']as $r){if(!is_array($r)||count($r)!==4||array_diff(array_keys($r),['x','y','width','height']))throw new \InvalidArgumentException('Exact subject geometry required.');foreach($r as $v)if(!is_int($v))throw new \InvalidArgumentException('Integer subject geometry required.');if($r['x']<1||$r['y']<1||$r['width']<3||$r['height']<3||$r['x']+$r['width']>=$w||$r['y']+$r['height']>=$h)throw new \InvalidArgumentException('Subject canvas padding required.');}
        $r=$s['subjects'][$s['selected']];foreach($s['foreground_points']as $p){if(!is_array($p)||count($p)!==2||array_diff(array_keys($p),['x','y'])||!is_int($p['x']??null)||!is_int($p['y']??null)||$p['x']<=$r['x']||$p['y']<=$r['y']||$p['x']>=$r['x']+$r['width']-1||$p['y']>=$r['y']+$r['height']-1)throw new \InvalidArgumentException('Preservation points must belong to selected subject.');}
    }

    private static function worker(string $input,int $outputBound=1500000):array
    {
        $python=getenv('DIGIFORGE_CUTOUT_PYTHON');if(!is_string($python)||$python===''||$python[0]!=='/'||!is_file($python)||!is_executable($python)||!function_exists('proc_open'))throw new \InvalidArgumentException('Offline processor not configured; reviewed alpha fallback required.');
        $environment=['PATH'=>'/usr/bin:/bin','OPENBLAS_NUM_THREADS'=>'1','OMP_NUM_THREADS'=>'1','MKL_NUM_THREADS'=>'1','PYTHONNOUSERSITE'=>'1'];$path=getenv('DIGIFORGE_CUTOUT_PYTHONPATH');if(is_string($path)&&$path!=='')$environment['PYTHONPATH']=$path;
        $pipes=[];$process=proc_open([$python,'-B','-s',__DIR__.'/offline/segment.py'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__,$environment,['bypass_shell'=>true]);if(!is_resource($process))throw new \InvalidArgumentException('Offline processor unavailable.');
        $output='';$written=0;$stdin=true;$exit=-1;$deadline=microtime(true)+18;
        try{foreach($pipes as $pipe)stream_set_blocking($pipe,false);
            while(true){if(microtime(true)>$deadline)throw new \InvalidArgumentException('Offline processor exceeded time bound.');
                if($stdin){$n=@fwrite($pipes[0],substr($input,$written,65536));if($n===false)throw new \InvalidArgumentException('Offline processor input failed.');$written+=$n;if($written===strlen($input)){fclose($pipes[0]);$stdin=false;}}
                $chunk=stream_get_contents($pipes[1],65536);if(is_string($chunk))$output.=$chunk;stream_get_contents($pipes[2],65536);if(strlen($output)>$outputBound)throw new \InvalidArgumentException('Offline processor exceeded output bound.');
                $status=proc_get_status($process);if(!$status['running']){$exit=$status['exitcode'];$tail=stream_get_contents($pipes[1],$outputBound+1-strlen($output));if(is_string($tail))$output.=$tail;break;}usleep(1000);
            }
            if($exit!==0||strlen($output)>$outputBound)throw new \InvalidArgumentException('Offline segmentation failed; reviewed alpha fallback required.');$result=json_decode($output,true,32,JSON_THROW_ON_ERROR);if(!is_array($result))throw new \InvalidArgumentException('Offline processor result invalid.');return $result;
        }finally{$status=proc_get_status($process);if($status['running'])proc_terminate($process,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
    }
}
