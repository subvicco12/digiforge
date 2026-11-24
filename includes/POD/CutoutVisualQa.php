<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Observed pixel metrics, not semantic, artistic, licensing or supplier certification. */
final class CutoutVisualQa
{
    public const VERSION='cutout-pixel-qa-v1';
    public static function measure(string $png,?string $reference=null):array
    {
        $image=RasterArtworkCodec::decode($png);$w=$image['width_px'];$h=$image['height_px'];$n=$w*$h;
        $r=$reference===null?null:RasterArtworkCodec::decode($reference);
        if($r!==null&&($r['width_px']!==$w||$r['height_px']!==$h))throw new \InvalidArgumentException('Reference must use exact source pixel geometry.');
        $visible=0;$soft=0;$boundary=0;$variation=0;$absolute=0;$squared=0;$intersection=0;$union=0;
        $minX=$w;$minY=$h;$maxX=-1;$maxY=-1;
        for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){
            $i=($y*$w+$x)*4+3;$a=ord($image['rgba'][$i]);
            if($a>0){$visible++;$minX=min($minX,$x);$maxX=max($maxX,$x);$minY=min($minY,$y);$maxY=max($maxY,$y);if($a<255)$soft++;if($x===0||$y===0||$x===$w-1||$y===$h-1)$boundary++;}
            if($x+1<$w)$variation+=abs($a-ord($image['rgba'][$i+4]));if($y+1<$h)$variation+=abs($a-ord($image['rgba'][$i+$w*4]));
            if($r!==null){$b=ord($r['rgba'][$i]);$d=abs($a-$b);$absolute+=$d;$squared+=$d*$d;if($a>=128&&$b>=128)$intersection++;if($a>=128||$b>=128)$union++;}
        }
        $defects=[];if($visible===0)$defects[]='empty_foreground';if($visible===$n)$defects[]='no_transparent_background';if($boundary>0)$defects[]='boundary_clipping';
        return ['version'=>self::VERSION,'artifact_sha256'=>hash('sha256',$png),'pixel_sha256'=>$image['pixel_sha256'],'width_px'=>$w,'height_px'=>$h,'visible_pixels'=>$visible,'soft_alpha_pixels'=>$soft,'boundary_pixels'=>$boundary,'alpha_total_variation'=>$variation,'foreground_bounds'=>$visible?['x'=>$minX,'y'=>$minY,'width'=>$maxX-$minX+1,'height'=>$maxY-$minY+1]:null,'reference'=>['state'=>$r===null?'MISSING':'UNREVIEWED_COMPARISON','sha256'=>$reference===null?null:hash('sha256',$reference),'mean_absolute_alpha_error'=>$r===null?null:$absolute/(255*$n),'mean_squared_alpha_error'=>$r===null?null:$squared/(255*255*$n),'alpha_iou_128'=>$r===null||$union===0?null:$intersection/$union],'critical_defects'=>$defects,'state'=>$defects?'CRITICAL_DEFECT':'OPERATOR_REVIEW_REQUIRED','semantic_detail_evidence'=>'MISSING','print_resolution_evidence'=>'REQUIRES_APPROVED_PLACEMENT_GEOMETRY','commercial_certified'=>false,'supplier_certified'=>false,'external_execution_authorized'=>false];
    }
    /** Annotated regions are supplied comparisons, never machine-identified semantic truth. */
    public static function detailComparisons(string $png,string $reference,array $regions):array
    {
        $images=[RasterArtworkCodec::decode($png),RasterArtworkCodec::decode($reference)];$w=$images[0]['width_px'];$h=$images[0]['height_px'];
        if($images[1]['width_px']!==$w||$images[1]['height_px']!==$h||count($regions)<1||count($regions)>32)throw new \InvalidArgumentException('Bounded exact-geometry annotated comparisons required.');
        $result=[];$total=0;
        foreach($regions as $region){
            if(!is_array($region)||count($region)!==5||array_diff(array_keys($region),['kind','x','y','width','height'])||!in_array($region['kind']??null,['hair','ears','fur','whiskers','subject','background'],true))throw new \InvalidArgumentException('Typed detail region required.');
            foreach(['x','y','width','height']as $key)if(!is_int($region[$key]??null)||$region[$key]<0||$region[$key]>10000)throw new \InvalidArgumentException('Bounded integer region required.');
            $rw=$region['width'];$rh=$region['height'];$total+=$rw*$rh;
            if($rw<1||$rh<1||$region['x']+$rw>$w||$region['y']+$rh>$h||$total>RasterArtworkCodec::MAX_PIXELS)throw new \InvalidArgumentException('Region outside source or aggregate work bound.');
            $crops=[];foreach($images as $image){$pixels='';for($y=$region['y'];$y<$region['y']+$rh;$y++)$pixels.=substr($image['rgba'],($y*$w+$region['x'])*4,$rw*4);$crops[]=RasterArtworkCodec::encode($rw,$rh,$pixels);}
            $metrics=self::measure($crops[0],$crops[1]);$metrics['annotation_boundary_pixels']=$metrics['boundary_pixels'];unset($metrics['boundary_pixels'],$metrics['critical_defects'],$metrics['foreground_bounds']);$metrics['state']='UNREVIEWED_REGION_COMPARISON';$result[]=['region'=>$region,'metrics'=>$metrics,'semantic_certified'=>false];
        }
        return $result;
    }
    public static function previews(string $png):array
    {
        $image=RasterArtworkCodec::decode($png);$out=[];
        foreach(['light'=>255,'dark'=>24]as $name=>$background){$pixels=$image['rgba'];for($i=0,$n=strlen($pixels);$i<$n;$i+=4){$a=ord($pixels[$i+3]);for($c=0;$c<3;$c++)$pixels[$i+$c]=chr(intdiv(ord($pixels[$i+$c])*$a+$background*(255-$a)+127,255));$pixels[$i+3]="\xff";}$out[$name]=RasterArtworkCodec::encode($image['width_px'],$image['height_px'],$pixels);}
        return $out;
    }
}
