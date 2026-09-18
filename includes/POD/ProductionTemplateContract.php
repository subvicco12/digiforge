<?php

declare(strict_types=1);

namespace DigiForge\POD;

/** Fail-closed contract for an immutable, versioned supplier-specific production template. */
final class ProductionTemplateContract
{
    public const STATUSES=['DRAFT','GEOMETRY_LOCKED','SAMPLE_REQUIRED','VALIDATED','RETIRED'];
    public const PIPELINES=['PRINTIFY_NATIVE','DIGIFORGE_RENDER','DIGIFORGE_AI'];

    /** Build a template candidate only from normalized Printify catalog identity plus explicit geometry. */
    public static function fromPrintifyCatalog(array $catalog,array $geometry,array $template): array
    {
        if(($catalog['provider']??'')!=='printify') throw new \InvalidArgumentException('normalized Printify catalog evidence is required');
        $product=(string)($catalog['provider_product_key']??'');$variant=(string)($catalog['provider_variant_key']??'');
        if(!ctype_digit($product)||!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/',$variant,$match)) throw new \InvalidArgumentException('Printify catalog identity is invalid');
        if((int)$product<1||(int)$match[1]<1||(int)$match[2]<1) throw new \InvalidArgumentException('Printify blueprint, provider and variant identity must be positive');
        foreach(['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas'] as $reserved){
            if(array_key_exists($reserved,$template)) throw new \InvalidArgumentException($reserved.' is derived and cannot be overridden');
        }
        $input=array_merge($template,['supplier'=>'printify','provider_blueprint_id'=>(int)$product,'provider_id'=>(int)$match[1],'variant_ids'=>[(int)$match[2]],'print_areas'=>$geometry]);
        return self::normalize($input);
    }

    public static function normalize(array $input): array
    {
        $templateId=self::token($input['template_id']??null,'template_id');
        $version=self::positiveInt($input['template_version']??null,'template_version');
        $supplier=strtolower(self::token($input['supplier']??null,'supplier'));
        $blueprint=self::positiveInt($input['provider_blueprint_id']??null,'provider_blueprint_id');
        $provider=self::positiveInt($input['provider_id']??null,'provider_id');
        $variants=self::positiveIntList($input['variant_ids']??null,'variant_ids');
        $pipeline=strtoupper(self::token($input['personalization_pipeline']??null,'personalization_pipeline'));
        if(!in_array($pipeline,self::PIPELINES,true)) throw new \InvalidArgumentException('unsupported personalization_pipeline');
        $engine=strtoupper(self::token($input['personalization_engine']??null,'personalization_engine'));
        $status=strtoupper(self::token($input['template_status']??'DRAFT','template_status'));
        if(!in_array($status,self::STATUSES,true)) throw new \InvalidArgumentException('unsupported template_status');
        $areas=$input['print_areas']??null;
        if(!is_array($areas)||$areas===[]) throw new \InvalidArgumentException('print_areas is required');
        $normalizedAreas=[];$identities=[];
        foreach($areas as $area){
            if(!is_array($area)) throw new \InvalidArgumentException('print_area must be an object');
            $position=strtolower(self::token($area['position']??null,'position'));
            $method=strtolower(self::token($area['decoration_method']??null,'decoration_method'));
            $width=self::positiveInt($area['width_px']??null,'width_px');
            $height=self::positiveInt($area['height_px']??null,'height_px');
            $identity=hash('sha256',$position."\0".$method);
            if(isset($identities[$identity])) throw new \InvalidArgumentException('duplicate normalized print_area identity');
            $identities[$identity]=true;
            $normalizedAreas[]=['position'=>$position,'decoration_method'=>$method,'width_px'=>$width,'height_px'=>$height];
        }
        usort($normalizedAreas,static fn(array $a,array $b):int=>[$a['position'],$a['decoration_method'],$a['width_px'],$a['height_px']]<=>[$b['position'],$b['decoration_method'],$b['width_px'],$b['height_px']]);
        $canonical=['template_id'=>$templateId,'template_version'=>$version,'supplier'=>$supplier,'provider_blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_ids'=>$variants,'print_areas'=>$normalizedAreas,'personalization_pipeline'=>$pipeline,'personalization_engine'=>$engine,'template_status'=>$status];
        $encoded=wp_json_encode($canonical);
        if(!is_string($encoded)) throw new \RuntimeException('production template fingerprint encoding failed');
        $canonical['fingerprint']=hash('sha256',$encoded);
        return $canonical;
    }

    /** Fingerprint only production-material fields so version/status changes do not manufacture drift. */
    public static function materialFingerprint(array $normalized): string
    {
        $material=[];
        foreach(['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas','personalization_pipeline','personalization_engine'] as $field){if(!array_key_exists($field,$normalized)) throw new \InvalidArgumentException('normalized production template material is incomplete');$material[$field]=$normalized[$field];}
        $encoded=wp_json_encode($material);if(!is_string($encoded)) throw new \RuntimeException('production template material fingerprint encoding failed');return hash('sha256',$encoded);
    }

    /** Validated production versions are immutable; catalog drift creates a new candidate version. */
    public static function assertMutable(array $existing): void
    {
        $status=strtoupper(trim((string)($existing['template_status']??'')));
        if(in_array($status,['VALIDATED','RETIRED'],true)) throw new \LogicException('validated or retired production templates are immutable');
    }

    private static function token(mixed $value,string $field): string
    {
        if(!is_string($value)) throw new \InvalidArgumentException($field.' must be a string');
        $value=trim($value);if($value==='') throw new \InvalidArgumentException($field.' is required');return $value;
    }
    private static function positiveInt(mixed $value,string $field): int
    {
        if(!is_int($value)&&!(is_string($value)&&ctype_digit($value))) throw new \InvalidArgumentException($field.' must be a positive integer');
        $value=(int)$value;if($value<1) throw new \InvalidArgumentException($field.' must be a positive integer');return $value;
    }
    private static function positiveIntList(mixed $value,string $field): array
    {
        if(!is_array($value)||$value===[]) throw new \InvalidArgumentException($field.' is required');
        $out=[];foreach($value as $item)$out[]=self::positiveInt($item,$field);$out=array_values(array_unique($out));sort($out,SORT_NUMERIC);return $out;
    }
}
