<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Orders\Validator;

/** Draft artwork recipes. Source canvases never establish supplier print dimensions. */
final class DesignFamilyLibrary
{
    private static function root():string{return dirname(__DIR__,2).'/assets/pod-designs/v1/';}
    public static function inventory():array
    {
        $bytes=file_get_contents(self::root().'families.json');if(!is_string($bytes)||strlen($bytes)>65536)throw new \InvalidArgumentException('Bounded family inventory required.');$families=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);if(!is_array($families)||count($families)!==28)throw new \InvalidArgumentException('Exact family inventory required.');return $families;
    }
    public static function provenance(string $id):array
    {
        $family=self::inventory()[$id]??null;if(!is_array($family))throw new \InvalidArgumentException('Unknown versioned design family.');
        return ['id'=>$id,'version'=>1,'family_sha256'=>hash('sha256',Validator::canonicalJson($family)),'registry_sha256'=>hash_file('sha256',self::root().'registry.json'),'supplier_certified'=>false,'commercial_certified'=>false];
    }
    public static function component(string $id):array
    {
        if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$id))throw new \InvalidArgumentException('Exact library component required.');
        $registry=json_decode(file_get_contents(self::root().'registry.json'),true,16,JSON_THROW_ON_ERROR);$component=$registry['components'][$id]??$registry['decorations'][$id]??null;if(!is_array($component))throw new \InvalidArgumentException('Missing library component.');
        if(!preg_match('~^sources/(?:POD_Reusable_Vector_Components|FACE_REPEAT_Design_Family)/[A-Za-z0-9_-]+\.svg$~D',$component['source']))throw new \InvalidArgumentException('Unsafe component source path.');$source=self::root().$component['source'];$path=self::root().'components/'.$id.'.png';$bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)>16777216||!hash_equals($component['raster_sha256'],hash('sha256',$bytes))||!hash_equals($component['source_sha256'],hash_file('sha256',$source)))throw new \InvalidArgumentException('Library source/raster provenance changed.');
        static $decoded=[];if(!isset($decoded[$component['raster_sha256']])){$pixels=RasterArtworkCodec::decode($bytes);$decoded[$component['raster_sha256']]=['width_px'=>$pixels['width_px'],'height_px'=>$pixels['height_px']];}$pixels=$decoded[$component['raster_sha256']];if($pixels['width_px']!==$component['width_px']||$pixels['height_px']!==$component['height_px'])throw new \InvalidArgumentException('Library raster geometry changed.');
        return ['bytes'=>$bytes,'sha256'=>$component['raster_sha256'],'source_sha256'=>$component['source_sha256'],'mime'=>'image/png','width_px'=>$pixels['width_px'],'height_px'=>$pixels['height_px']];
    }

    public static function draft(array $template,string $id,array $settings,array $font=[]):array
    {
        $t=ProductionTemplateContract::normalize($template);$family=self::inventory()[$id]??null;if(!is_array($family))throw new \InvalidArgumentException('Unknown family.');
        if($family['status']!=='DRAFT_RECIPE_AVAILABLE')throw new \InvalidArgumentException('Approved transformed artwork required; illustrative painterly placeholder cannot be rendered.');
        if($t['supplier']!=='printify'||$t['template_status']!=='VALIDATED'||$t['personalization_pipeline']!=='DIGIFORGE_RENDER'||!in_array($t['personalization_engine'],$family['compatible_engines']??[$family['engine']],true))throw new \InvalidArgumentException('Exact validated supplier template and compatible renderer engine required.');
        $allowed=['background','text_color','font_size','face_fields','tile_width','tile_height','spacing_percent','rotations','include_name'];if(array_diff(array_keys($settings),$allowed))throw new \InvalidArgumentException('Unsupported family controls.');
        foreach(['background','text_color']as $color)if(!is_string($settings[$color]??null)||!preg_match('/^#[a-f0-9]{6}$/D',$settings[$color]))throw new \InvalidArgumentException('Explicit family colors required.');
        if(!is_int($settings['font_size']??null)||$settings['font_size']<1||$settings['font_size']>512)throw new \InvalidArgumentException('Explicit bounded font size required.');
        $repeat=str_starts_with($id,'FR');$fields=$family['fields'];$panels=[];$hasText=!$repeat||($settings['include_name']??false);
        if($hasText)$font=BitmapFontAdapter::normalize($font);
        if($repeat){$names=$settings['face_fields']??null;if(!is_array($names)||!array_is_list($names)||count($names)<1||count($names)>8||count(array_unique($names))!==count($names))throw new \InvalidArgumentException('One to eight distinct customer face fields required.');if(in_array($id,['FR05','FR08'],true)&&count($names)<2)throw new \InvalidArgumentException('Couple/multi-face family requires at least two distinct fields.');foreach($names as $n)if(!is_string($n)||!preg_match('/^face_[1-8]$/D',$n))throw new \InvalidArgumentException('Typed face field identity required.');
            foreach(['tile_width','tile_height','spacing_percent']as $n)if(!is_int($settings[$n]??null)||$settings[$n]<1||$settings[$n]>10000)throw new \InvalidArgumentException('Explicit bounded repeat dimensions required.');if($settings['spacing_percent']<25||$settings['spacing_percent']>200)throw new \InvalidArgumentException('Bounded repeat spacing required.');$rotations=$settings['rotations']??null;if(!is_array($rotations)||!array_is_list($rotations)||!$rotations||count($rotations)>16)throw new \InvalidArgumentException('Exact lossless rotation sequence required.');foreach($rotations as $r)if(!in_array($r,[0,90,180,270],true))throw new \InvalidArgumentException('Unsupported rotation.');if(isset($settings['include_name'])&&!is_bool($settings['include_name']))throw new \InvalidArgumentException('Name flag must be boolean.');
            foreach($names as $name)$fields[]=['key'=>$name,'type'=>'image_reference','required'=>true,'max_length'=>512];if($hasText){if($id==='FR07'){foreach($names as $n)$fields[]=['key'=>'name_'.substr($n,-1),'type'=>'text','required'=>false,'max_length'=>48];}else $fields[]=['key'=>'name','type'=>'text','required'=>false,'max_length'=>48];}
        }elseif(array_intersect(array_keys($settings),['face_fields','tile_width','tile_height','spacing_percent','rotations','include_name']))throw new \InvalidArgumentException('Repeat controls require a repeat family.');
        foreach($t['print_areas']as $area){$qa=ArtworkGeometryQa::policy($area);if($qa['state']!=='VERIFIED_LOCAL_GEOMETRY')throw new \InvalidArgumentException('Explicit reviewed safe-zone/seam/resolution specifications required; none are inferred from prototype canvas.');$b=$qa['safe_zone'];$slots=[];if($repeat){$size=min($b['width'],$b['height']);$b=['x'=>$b['x']+intdiv($b['width']-$size,2),'y'=>$b['y']+intdiv($b['height']-$size,2),'width'=>$size,'height'=>$size];}
            if($repeat){if(isset($family['decoration']))$slots[]=['type'=>'component','component_id'=>$family['decoration'],'x'=>$b['x'],'y'=>$b['y'],'width'=>$b['width'],'height'=>$b['height']];foreach($family['positions']as $i=>$point){$cx=$b['x']+intdiv($b['width'],2)+(int)round(($point[0]/$family['canvas'][0]-.5)*$b['width']*$settings['spacing_percent']/100);$cy=$b['y']+intdiv($b['height'],2)+(int)round(($point[1]/$family['canvas'][1]-.5)*$b['height']*$settings['spacing_percent']/100);$slot=['type'=>'image','field'=>$names[$i%count($names)],'cutout'=>true,'x'=>$cx-intdiv($settings['tile_width'],2),'y'=>$cy-intdiv($settings['tile_height'],2),'width'=>$settings['tile_width'],'height'=>$settings['tile_height'],'repeat'=>['columns'=>1,'rows'=>1,'gap_x'=>0,'gap_y'=>0,'rotations'=>[$rotations[$i%count($rotations)]]]];ArtworkGeometryQa::placement($qa,$slot);$slots[]=$slot;if($id==='FR07'&&$hasText){$box=max(1,(int)round(76*$b['width']/560));$slots[]=['type'=>'text','field'=>'name_'.substr($names[$i%count($names)],-1),'optional'=>true,'x'=>$cx-intdiv($box,2),'y'=>$cy+(int)round(49*$b['height']/560),'font_size'=>$settings['font_size'],'color'=>$settings['text_color'],'max_characters'=>48,'align'=>'center','box_width'=>$box];}}
                if($hasText&&$id!=='FR07')$slots[]=['type'=>'text','field'=>'name','optional'=>true,'x'=>$b['x'],'y'=>$b['y']+$b['height']-$settings['font_size'],'font_size'=>$settings['font_size'],'color'=>$settings['text_color'],'max_characters'=>48,'align'=>'center','box_width'=>$b['width']];
            }else{
                $slots[]=['type'=>'component','component_id'=>$family['component'],'x'=>$b['x']+intdiv($b['width'],5),'y'=>$b['y'],'width'=>max(1,intdiv($b['width']*3,5)),'height'=>max(1,intdiv($b['height'],2))];
                $textIndex=0;foreach($fields as $field){if($field['type']==='image_reference'){$slots[]=['type'=>'image','field'=>$field['key'],'reviewed_asset'=>true,'x'=>$b['x']+intdiv($b['width'],4),'y'=>$b['y']+intdiv($b['height'],12),'width'=>max(1,intdiv($b['width'],2)),'height'=>max(1,intdiv($b['height'],3))];}else{$slots[]=['type'=>'text','field'=>$field['key'],'optional'=>!$field['required'],'x'=>$b['x'],'y'=>$b['y']+intdiv($b['height']*3,5)+$textIndex*($settings['font_size']*2),'font_size'=>$settings['font_size'],'color'=>$settings['text_color'],'max_characters'=>$field['max_length'],'align'=>'center','box_width'=>$b['width']];$textIndex++;}}
            }foreach($slots as $slot){$box=$slot['type']==='text'?['x'=>$slot['x'],'y'=>$slot['y']-$slot['font_size'],'width'=>$slot['box_width'],'height'=>$slot['font_size']]:$slot;ArtworkGeometryQa::placement($qa,$box);}$panels[$area['position']]=['slots'=>$slots];
        }
        $recipe=['engine'=>$t['personalization_engine'],'background'=>$settings['background'],'panels'=>$panels,'design_family'=>self::provenance($id)];if($hasText)$recipe['bitmap_font']=$font;
        return ['family_id'=>$id,'version'=>1,'schema'=>$fields,'recipe'=>$recipe,'state'=>'REVIEW_REQUIRED','supplier_certified'=>false,'commercial_certified'=>false,'external_execution_authorized'=>false];
    }
}
