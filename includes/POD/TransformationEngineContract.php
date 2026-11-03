<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Owner-approved design revision; named styles/models/fixtures are separate evidence. */
final class TransformationEngineContract
{
    public const VERSION='1.0.0';
    public const ENGINES=['PET_PORTRAIT','WATERCOLOR','LINE_ART','VINTAGE_PORTRAIT','AI_TRANSFORM'];
    public static function capabilities():array
    {
        $out=[];foreach(self::ENGINES as $engine)$out[$engine]=['contract_version'=>self::VERSION,'design_direction'=>'OWNER_APPROVED','adapter'=>'REVIEWED_STORED_RESULT_IMPORT','deterministic_artistic_transform'=>false,'catalog_activation_allowed'=>false,'catalog_blocker'=>$engine==='VINTAGE_PORTRAIT'?'FIRST_APPROVED_PRODUCT_AND_STYLE_REQUIRED':'LICENSED_FIXTURES_AND_SEPARATE_PROMOTION_REQUIRED','certification'=>'NOT_CERTIFIED','external_execution_authorized'=>false];return $out;
    }
    public static function token(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,99}$/D',$value))throw new \InvalidArgumentException('Exact bounded server-reviewed identity required.');return $value;
    }
    public static function hash(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))throw new \InvalidArgumentException('Exact SHA-256 required.');return $value;
    }
    public static function exact(array $value,array $fields):void
    {
        if(array_diff(array_keys($value),$fields)!==[])throw new \InvalidArgumentException('Undeclared contract fields are forbidden.');
    }
    public static function options(string $engine,string $version,array $options,?array $mode=null):array
    {
        if(!in_array($engine,self::ENGINES,true)||$version!==self::VERSION)throw new \InvalidArgumentException('Supported exact engine contract version required.');
        if($engine==='AI_TRANSFORM'){
            self::exact($options,['mode','mode_version','variables']);$name=self::token($options['mode']??null);$revision=self::token($options['mode_version']??null);
            if($mode===null||($mode['mode']??null)!==$name||($mode['version']??null)!==$revision||!is_array($options['variables']??null))throw new \InvalidArgumentException('Exact approved shop-scoped mode and typed variables required.');
            PersonalizationPayloadContract::validate((array)$mode['input_fields'],$options['variables']);return ['mode'=>$name,'mode_version'=>$revision,'variables'=>$options['variables'],'mode_sha256'=>hash('sha256',\DigiForge\Orders\Validator::canonicalJson($mode))];
        }
        $allowed=match($engine){'PET_PORTRAIT'=>['subject_count'],'WATERCOLOR'=>['palette','background'],'LINE_ART'=>['colors','minimum_stroke_px'],'VINTAGE_PORTRAIT'=>['era_reference','disclosure']};
        self::exact($options,array_merge(['style_reference'],$allowed));$out=['style_reference'=>self::token($options['style_reference']??null)];
        if($engine==='PET_PORTRAIT'){if(($options['subject_count']??1)!==1)throw new \InvalidArgumentException('Multiple pets require a separate approved contract.');$out['subject_count']=1;}
        if($engine==='WATERCOLOR'){$palette=$options['palette']??'neutral';$background=$options['background']??'paper';if($palette!=='neutral'||!in_array($background,['paper','transparent'],true))throw new \InvalidArgumentException('Only the approved neutral/paper-or-transparent design is supported.');$out+=['palette'=>$palette,'background'=>$background];}
        if($engine==='LINE_ART'){if(($options['colors']??1)!==1)throw new \InvalidArgumentException('Only monochrome direction approved.');$stroke=$options['minimum_stroke_px']??null;if($stroke!==null&&(!is_int($stroke)||$stroke<1||$stroke>10000))throw new \InvalidArgumentException('Explicit positive supplier stroke bound required.');$out+=['colors'=>1,'minimum_stroke_px'=>$stroke];}
        if($engine==='VINTAGE_PORTRAIT'){if(isset($options['disclosure'])&&$options['disclosure']!=='STYLIZED_VINTAGE_INSPIRED')throw new \InvalidArgumentException('Historic-authenticity claims are forbidden.');$out+=['era_reference'=>isset($options['era_reference'])?self::token($options['era_reference']):null,'disclosure'=>'STYLIZED_VINTAGE_INSPIRED'];}
        return $out;
    }
    public static function limits(array $limits):array
    {
        $bounds=['max_input_bytes'=>16777216,'max_output_bytes'=>16777216,'max_pixels'=>RasterArtworkCodec::MAX_PIXELS,'max_execution_seconds'=>3600,'max_variants'=>1,'max_retries'=>10];self::exact($limits,array_keys($bounds));
        foreach($bounds as $field=>$maximum){$v=$limits[$field]??null;if(!is_int($v)||$v<($field==='max_retries'?0:1)||$v>$maximum)throw new \InvalidArgumentException('Explicit owner/host resource limits within local capability required.');}return $limits;
    }
}
