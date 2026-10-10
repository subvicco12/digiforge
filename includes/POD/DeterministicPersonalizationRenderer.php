<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use WP_Error;

/** Bounded local text/photo SVG renderer. No AI, network, provider placement or production authority. */
final class DeterministicPersonalizationRenderer
{
    public function approveRecipe(string $key,int $version,array $recipe):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required','Authorized POD recipe reviewer required.');
        $template=$this->storedTemplate($key,$version);if($template instanceof WP_Error)return $template;
        $owned=(new TemplateCycleRepository())->assertOwner('personalized_pod',$template['fingerprint']);if($owned instanceof WP_Error)return $owned;
        $normalized=$this->normalizeRecipe($template,$recipe);if($normalized instanceof WP_Error)return $normalized;
        $proof=['template_sha256'=>$template['fingerprint'],'recipe'=>$normalized,'recipe_sha256'=>hash('sha256',Validator::canonicalJson($normalized)),'renderer_version'=>'local-svg-v1','external_execution_authorized'=>false];
        $option='digiforge_render_recipe_'.$template['fingerprint'];$old=$this->read($option);if($old instanceof WP_Error)return $old;if($old!==null){$previous=$old;unset($previous['approved_by'],$previous['approved_at']);return $previous===$proof?$old+['idempotent_replay'=>true]:self::error('recipe_conflict','Approved recipe is immutable; use a new template version.');}
        global $wpdb;$saved=$proof+['approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true)];$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>$option,'option_value'=>wp_json_encode($saved),'autoload'=>'no']);$confirmed=$this->read($option);return $confirmed===$saved?$confirmed:self::error('recipe_uncertain','Recipe persistence could not be confirmed.');
    }
    public function expected(array $render,?string $position=null):array|WP_Error
    {
        $template=(new RenderArtifactVerifier())->certifiedTemplate($render);if($template instanceof WP_Error)return $template;
        $proof=$this->read('digiforge_render_recipe_'.$template['fingerprint']);if($proof instanceof WP_Error)return $proof;if($proof===null||($proof['approved_by']??0)<1||($proof['template_sha256']??null)!==$template['fingerprint'])return self::error('recipe_required','Exact approved versioned render recipe required.');
        $recipe=$this->normalizeRecipe($template,$proof['recipe']);if($recipe instanceof WP_Error)return $recipe;if(!hash_equals($proof['recipe_sha256'],hash('sha256',Validator::canonicalJson($recipe))))return self::error('recipe_conflict','Stored recipe fingerprint is invalid.');
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare("SELECT ps.*,s.field_definitions,s.state schema_state,s.approved_by schema_reviewer,s.approved_at schema_reviewed_at FROM ".Tables::personalization_submissions()." ps INNER JOIN ".Tables::order_line_items()." li ON li.id=ps.order_line_item_id INNER JOIN ".Tables::personalization_schemas()." s ON s.id=ps.personalization_schema_id AND s.product_version_id=li.product_version_id WHERE li.order_id=%d AND li.provider_mapping_id=%d AND li.validation_status='VALIDATED' AND ps.payload_hash=%s AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL",(int)$render['order_id'],(int)$render['provider_mapping_id'],$render['personalization_evidence_hash']),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1)return self::error('personalization_required','Exactly one current approved canonical personalization and matching schema are required.');$row=$rows[0];$payload=json_decode((string)$row['canonical_payload'],true);
        if($row['schema_state']!=='APPROVED'||(int)$row['schema_reviewer']<1||$row['schema_reviewed_at']===null||!is_array($payload)||!hash_equals($row['payload_hash'],hash('sha256',(string)$row['canonical_payload'])))return self::error('personalization_conflict','Stored approved payload bytes and schema evidence must agree.');
        try{if(Validator::canonicalJson($payload)!==$row['canonical_payload'])return self::error('personalization_conflict','Approved payload must retain its exact canonical representation.');}catch(\Throwable){return self::error('personalization_conflict','Canonical personalization is invalid.');}
        $panels=$recipe['panels'];if($position===null){if(count($panels)!==1)return self::error('panel_required','Explicit panel identity required for multi-panel rendering.');$position=array_key_first($panels);}
        if(!isset($panels[$position]))return self::error('panel_invalid','Panel is not in the approved template.');$panel=$panels[$position];$width=$panel['width_px'];$height=$panel['height_px'];
        $svg='<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'">' ."\n";
        $svg.='<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="'.$recipe['background'].'"/>' ."\n";
        foreach($panel['slots']as $slot){$value=$payload[$slot['field']]??null;if(!is_string($value)||$value===''||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))return self::error('field_invalid','Approved text or protected image reference is missing or invalid.');
            if($slot['type']==='text'){
                if(strlen($value)>$slot['max_characters']||strlen($value)*$slot['font_size']>$width-$slot['x'])return self::error('text_overflow','Text exceeds the approved conservative layout bound.');
                $svg.='<text x="'.$slot['x'].'" y="'.$slot['y'].'" font-family="monospace" font-size="'.$slot['font_size'].'" fill="'.$slot['color'].'">'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</text>' ."\n";
            }else{
                $path=\DigiForge\ProductFactory\AssetStorage::absolutePath($value);if($path===null)return self::error('image_unavailable','Approved protected local image artifact is unavailable.');$image=(new RenderArtifactVerifier())->readArtifact($path);if($image instanceof WP_Error)return $image;if(!in_array($image['mime'],['image/png','image/jpeg'],true))return self::error('image_invalid','Only bounded local PNG/JPEG photos are supported.');$bytes=@file_get_contents($path);if(!is_string($bytes)||!hash_equals($image['sha256'],hash('sha256',$bytes)))return self::error('image_uncertain','Approved photo changed during rendering.');
                $svg.='<image x="'.$slot['x'].'" y="'.$slot['y'].'" width="'.$slot['width'].'" height="'.$slot['height'].'" preserveAspectRatio="xMidYMid meet" href="data:'.$image['mime'].';base64,'.base64_encode($bytes).'"/>' ."\n";
            }
        }
        $svg.="</svg>\n";if(strlen($svg)>16777216)return self::error('size_invalid','Rendered output exceeds safe size.');
        return ['svg'=>$svg,'sha256'=>hash('sha256',$svg),'recipe_sha256'=>$proof['recipe_sha256'],'renderer_version'=>'local-svg-v1','position'=>$position,'width_px'=>$width,'height_px'=>$height,'external_execution_authorized'=>false];
    }
    /** Safe inspection only. Certification additionally recomputes the exact approved output. */
    public static function inspectSvg(string $bytes):array|WP_Error
    {
        if(strlen($bytes)>16777216||preg_match('/<!|<\?/',$bytes))return self::error('svg_invalid','SVG declarations and entities are unsupported.');
        $doc=new \DOMDocument();$previous=libxml_use_internal_errors(true);try{$ok=$doc->loadXML($bytes,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}if(!$ok||$doc->doctype!==null||$doc->documentElement===null)return self::error('svg_invalid','Safe SVG XML required.');$root=$doc->documentElement;
        if($root->nodeName!=='svg'||$root->namespaceURI!=='http://www.w3.org/2000/svg')return self::error('svg_invalid','SVG namespace required.');
        $width=$root->getAttribute('width');$height=$root->getAttribute('height');if(!ctype_digit($width)||!ctype_digit($height)||(int)$width<1||(int)$height<1||(int)$width>10000||(int)$height>10000||$root->getAttribute('viewBox')!=='0 0 '.$width.' '.$height)return self::error('svg_invalid','Bounded exact SVG geometry required.');
        $allowed=['svg'=>['xmlns','width','height','viewBox'],'rect'=>['x','y','width','height','fill'],'text'=>['x','y','font-family','font-size','fill'],'image'=>['x','y','width','height','preserveAspectRatio','href']];
        foreach($doc->getElementsByTagName('*')as $node){if(!isset($allowed[$node->nodeName])||$node->namespaceURI!==$root->namespaceURI||($node!==$root&&$node->parentNode!==$root))return self::error('svg_invalid','Only bounded generated SVG primitives are supported.');foreach($node->attributes as $attribute)if(!in_array($attribute->nodeName,$allowed[$node->nodeName],true))return self::error('svg_invalid','Unsupported SVG attribute.');if($node->nodeName==='image'&&!preg_match('/^data:image\/(?:png|jpeg);base64,[A-Za-z0-9+\/=]+$/D',$node->getAttribute('href')))return self::error('svg_invalid','Only embedded local image bytes are supported.');}
        return ['width_px'=>(int)$width,'height_px'=>(int)$height,'mime'=>'image/svg+xml'];
    }
    private function normalizeRecipe(array $template,array $recipe):array|WP_Error
    {
        if($template['personalization_pipeline']!=='DIGIFORGE_RENDER'||($recipe['engine']??null)!==$template['personalization_engine']||!in_array($recipe['engine'],['NAME_MONOGRAM','PHOTO_TEXT','SINGLE_PHOTO','PHOTO_COLLAGE','MULTI_PHOTO'],true))return self::error('engine_unsupported','Explicit supported deterministic template engine required.');
        if(array_diff(array_keys($recipe),['engine','background','slots','panels'])!==[]||!preg_match('/^#[a-f0-9]{6}$/D',(string)($recipe['background']??'')))return self::error('recipe_invalid','Exact safe recipe fields required.');
        $panels=$recipe['panels']??null;if($panels===null){if(count($template['print_areas'])!==1)return self::error('panel_required','Complete panel recipes required.');$panels=[$template['print_areas'][0]['position']=>['slots'=>$recipe['slots']??null]];}
        if(!is_array($panels)||count($panels)!==count($template['print_areas']))return self::error('panel_invalid','Recipe must cover every template panel exactly.');$normalized=[];
        foreach($template['print_areas']as $area){$position=$area['position'];$panel=$panels[$position]??null;$slots=is_array($panel)?($panel['slots']??null):null;if(!is_array($slots)||$slots===[]||count($slots)>32||array_diff(array_keys($panel),['slots','width_px','height_px'])!==[])return self::error('recipe_invalid','Bounded panel slots required.');$width=$area['width_px'];$height=$area['height_px'];if($width>10000||$height>10000)return self::error('geometry_unsupported','Local renderer geometry exceeds safe bound.');$out=[];
            foreach($slots as $slot){if(!is_array($slot)||!in_array($slot['type']??null,['text','image'],true)||!is_string($slot['field']??null)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$slot['field']))return self::error('slot_invalid','Explicit text/image fields required.');foreach(['x','y']as $n)if(!is_int($slot[$n]??null)||$slot[$n]<0||$slot[$n]>($n==='x'?$width:$height))return self::error('slot_invalid','Integer slot geometry required.');
                if($slot['type']==='text'){if(array_diff(array_keys($slot),['type','field','x','y','font_size','color','max_characters'])!==[]||!is_int($slot['font_size']??null)||$slot['font_size']<1||$slot['font_size']>512||!is_int($slot['max_characters']??null)||$slot['max_characters']<1||$slot['max_characters']>1024||!preg_match('/^#[a-f0-9]{6}$/D',(string)($slot['color']??''))||$slot['y']<$slot['font_size']||$slot['y']+$slot['font_size']>$height)return self::error('slot_invalid','Bounded text layout required.');}
                else{if(array_diff(array_keys($slot),['type','field','x','y','width','height'])!==[]||!is_int($slot['width']??null)||!is_int($slot['height']??null)||$slot['width']<1||$slot['height']<1||$slot['x']+$slot['width']>$width||$slot['y']+$slot['height']>$height)return self::error('slot_invalid','Bounded image placement required.');}
                $out[]=$slot;
            }$normalized[$position]=['width_px'=>$width,'height_px'=>$height,'slots'=>$out];
        }ksort($normalized);return ['engine'=>$recipe['engine'],'background'=>$recipe['background'],'panels'=>$normalized];
    }
    private function storedTemplate(string $key,int $version):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d',$key,$version),ARRAY_A);if($wpdb->last_error!==''||!is_array($row)||$row['template_status']!=='VALIDATED')return self::error('template_required','Exact stored VALIDATED template required.');$row['variant_ids']=json_decode($row['variant_ids'],true);$row['print_areas']=json_decode($row['print_areas'],true);try{$normalized=ProductionTemplateContract::normalize($row);}catch(\Throwable){return self::error('template_invalid','Template invalid.');}return hash_equals($row['fingerprint'],$normalized['fingerprint'])?$normalized:self::error('template_conflict','Stored template fingerprint invalid.');
    }
    private function read(string $key):array|WP_Error|null{global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key));if($wpdb->last_error!=='')return self::error('unavailable','Recipe evidence unavailable.');if($raw===null)return null;$value=json_decode((string)$raw,true);return is_array($value)?$value:self::error('corrupt','Recipe evidence corrupt.');}
    private static function error(string $code,string $message):WP_Error{return new WP_Error('deterministic_render_'.$code,$message,['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
