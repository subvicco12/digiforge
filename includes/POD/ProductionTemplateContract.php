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
        return self::fromPrintifyVariants([$catalog],$geometry,$template);
    }

    /** Build from persisted, exact Printify variant rows; geometry is never accepted as provider evidence. */
    public static function fromPrintifyVariants(array $catalogVariants,array $geometry,array $template): array
    {
        if($catalogVariants===[]||$geometry===[])throw new \InvalidArgumentException('persisted Printify variants and geometry are required');
        $first=self::catalogIdentity($catalogVariants[0]);$product=$first['blueprint_id'];$provider=$first['provider_id'];$environment=$first['environment'];$variants=[];$expected=[];
        foreach($catalogVariants as $catalog){
            if(!is_array($catalog))throw new \InvalidArgumentException('Printify variant row is invalid');
            $identity=self::catalogIdentity($catalog);
            if($identity['blueprint_id']!==$product||$identity['provider_id']!==$provider||$identity['environment']!==$environment)throw new \InvalidArgumentException('selected Printify variants must share exact blueprint, provider, and environment');
            if(isset($variants[$identity['variant_id']]))throw new \InvalidArgumentException('duplicate Printify variant binding');
            $variants[$identity['variant_id']]=$identity;
            foreach($identity['placeholders'] as $placeholder){$key=$placeholder['position']."\0".$placeholder['decoration_method'];$shape=['position'=>$placeholder['position'],'decoration_method'=>$placeholder['decoration_method'],'width_px'=>$placeholder['width'],'height_px'=>$placeholder['height']];if(isset($expected[$key])&&($expected[$key]['width_px']!==$shape['width_px']||$expected[$key]['height_px']!==$shape['height_px']))throw new \InvalidArgumentException('selected variants have different Printify placeholder geometry; split the template');$expected[$key]=$shape;}
        }
        $expectedKeys=array_keys($expected);sort($expectedKeys,SORT_STRING);foreach($variants as $identity){$keys=array_keys($identity['by_key']);sort($keys,SORT_STRING);if($keys!==$expectedKeys)throw new \InvalidArgumentException('selected Printify variants must expose identical placement sets; split the template');}
        if(count($geometry)!==count($expected))throw new \InvalidArgumentException('geometry must contain exactly the Printify variant placements');
        $bound=[];
        $requiresRegion=strtoupper((string)($template['template_status']??'DRAFT'))!=='DRAFT';foreach($geometry as $area){if(!is_array($area))throw new \InvalidArgumentException('print geometry must be objects');$position=strtolower(self::token($area['position']??null,'position'));$method=strtolower(self::token($area['decoration_method']??null,'decoration_method'));$key=$position."\0".$method;if(!isset($expected[$key]))throw new \InvalidArgumentException('placement or decoration method is absent from the selected Printify variant');$shape=$expected[$key];if((int)($area['width_px']??0)!==$shape['width_px']||(int)($area['height_px']??0)!==$shape['height_px'])throw new \InvalidArgumentException('caller geometry does not match authoritative Printify placeholder dimensions');if($requiresRegion&&(!is_string($area['region']??null)||preg_match('/^[A-Z]{2,3}$/D',$area['region'])!==1))throw new \InvalidArgumentException('non-draft Printify geometry requires an explicit provider region');$supplement=$area;unset($supplement['position'],$supplement['decoration_method'],$supplement['width_px'],$supplement['height_px']);$bindings=[];foreach($variants as $id=>$identity){$placeholder=$identity['by_key'][$key];$bindings[]=['schema'=>'printify-placeholder-binding-v1','provider'=>'printify','environment'=>$environment,'blueprint_id'=>$product,'provider_id'=>$provider,'variant_id'=>$id,'endpoint_path'=>$identity['evidence']['endpoint_path'],'response_sha256'=>$identity['evidence']['response_sha256'],'source_revision'=>$identity['source_revision'],'observed_at'=>$identity['evidence']['observed_at'],'variant_sha256'=>$identity['evidence']['variant_sha256'],'placeholder_sha256'=>hash('sha256',self::json($placeholder))];}usort($bindings,static fn(array $a,array $b):int=>$a['variant_id']<=>$b['variant_id']);$supplement['_catalog_bindings']=$bindings;$bound[]=$shape+$supplement;}
        foreach(['supplier','provider_blueprint_id','provider_id','variant_ids','print_areas'] as $reserved){
            if(array_key_exists($reserved,$template)) throw new \InvalidArgumentException($reserved.' is derived and cannot be overridden');
        }
        $variantIds=array_map('intval',array_keys($variants));sort($variantIds,SORT_NUMERIC);
        $input=array_merge($template,['supplier'=>'printify','provider_blueprint_id'=>$product,'provider_id'=>$provider,'variant_ids'=>$variantIds,'print_areas'=>$bound]);
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
            $normalized=['position'=>$position,'decoration_method'=>$method,'width_px'=>$width,'height_px'=>$height];
            $supplemental=$area;
            foreach(['position','decoration_method','width_px','height_px'] as $field)unset($supplemental[$field]);
            if($supplemental!==[]){
                $supplemental=self::geometryEvidence($supplemental);
                $encoded=wp_json_encode($supplemental);
                if(!is_string($encoded)||strlen($encoded)>65536)throw new \InvalidArgumentException('supplemental geometry evidence exceeds safe size');
                $normalized=array_merge($normalized,$supplemental);
            }
            if($supplier==='printify'&&$status!=='DRAFT')self::assertPrintifyBindings($normalized,$blueprint,$provider,$variants);
            $normalizedAreas[]=$normalized;
        }
        // Original Blueprint p103 rejects this blueprint for the US route. Missing
        // route evidence cannot silently assume a permitted region.
        if($supplier==='printify'&&$blueprint===1509){
            $regions=[];
            foreach($normalizedAreas as $area){$region=$area['region']??null;if(!is_string($region)||preg_match('/^[A-Za-z]{2}$/D',$region)!==1)throw new \InvalidArgumentException('Printify blueprint 1509 requires explicit region evidence');$region=strtoupper($region);if($region==='US')throw new \InvalidArgumentException('Printify blueprint 1509 is rejected for the US route');$regions[$region]=true;}
            if(count($regions)!==1)throw new \InvalidArgumentException('Printify blueprint 1509 region evidence is conflicting');
        }
        usort($normalizedAreas,static fn(array $a,array $b):int=>[$a['position'],$a['decoration_method'],$a['width_px'],$a['height_px']]<=>[$b['position'],$b['decoration_method'],$b['width_px'],$b['height_px']]);
        if($supplier==='printify'&&$status!=='DRAFT'){$environments=[];$regions=[];foreach($normalizedAreas as $area){$regions[(string)($area['region']??'')]=true;foreach((array)($area['_catalog_bindings']??[]) as $binding)$environments[(string)($binding['environment']??'')]=true;}if(count($regions)!==1||isset($regions[''])||count($environments)!==1||isset($environments['']))throw new \InvalidArgumentException('non-draft Printify placements must share an explicit region and catalog environment');}
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

    private static function catalogIdentity(array $row): array
    {
        if(($row['provider']??'')!=='printify')throw new \InvalidArgumentException('persisted Printify catalog row is required');
        $environment=(string)($row['environment']??'');$product=(string)($row['provider_product_key']??'');$variant=(string)($row['provider_variant_key']??'');
        if(!in_array($environment,['sandbox','test','production'],true)||!ctype_digit($product)||!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D',$variant,$m))throw new \InvalidArgumentException('Printify catalog identity is invalid');
        $attributes=$row['attributes']??null;if(is_string($attributes))$attributes=json_decode($attributes,true);if(!is_array($attributes))throw new \InvalidArgumentException('persisted Printify variant attributes are required');
        $evidence=$attributes['_digiforge_source_evidence']??null;if(!is_array($evidence)||($evidence['schema']??null)!=='printify-variant-evidence-v1')throw new \InvalidArgumentException('transport-derived Printify source evidence is required');
        $blueprint=(int)$product;$provider=(int)$m[1];$variantId=(int)$m[2];
        foreach(['blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment] as $field=>$expected)if(($evidence[$field]??null)!==$expected)throw new \InvalidArgumentException('Printify source evidence identity does not match catalog row');
        $path=(string)($evidence['endpoint_path']??'');if($path!=="/v1/catalog/blueprints/$blueprint/print_providers/$provider/variants.json"||!self::isSha256($evidence['response_sha256']??null)||!self::isSha256($evidence['variant_sha256']??null)||!is_string($evidence['observed_at']??null)||strtotime((string)$evidence['observed_at'])===false)throw new \InvalidArgumentException('Printify source provenance is incomplete');
        $actual=wp_json_encode(array_diff_key($attributes,['_digiforge_source_evidence'=>true]),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($actual)||!hash_equals((string)$evidence['variant_sha256'],hash('sha256',$actual)))throw new \InvalidArgumentException('Printify variant payload does not match its source digest');
        $placeholders=$attributes['placeholders']??null;if(!is_array($placeholders)||$placeholders===[])throw new \InvalidArgumentException('Printify variant has no authoritative placeholders');$byKey=[];
        foreach($placeholders as $placeholder){if(!is_array($placeholder))throw new \InvalidArgumentException('Printify placeholder is invalid');$position=strtolower(self::token($placeholder['position']??null,'Printify placement'));$method=strtolower(self::token($placeholder['decoration_method']??null,'Printify decoration method'));$width=self::positiveInt($placeholder['width']??null,'Printify placeholder width');$height=self::positiveInt($placeholder['height']??null,'Printify placeholder height');$key=$position."\0".$method;if(isset($byKey[$key]))throw new \InvalidArgumentException('duplicate Printify placement and decoration method');$byKey[$key]=['position'=>$position,'decoration_method'=>$method,'width'=>$width,'height'=>$height];}
        ksort($byKey,SORT_STRING);return ['blueprint_id'=>$blueprint,'provider_id'=>$provider,'variant_id'=>$variantId,'environment'=>$environment,'source_revision'=>(string)($row['source_revision']??''),'evidence'=>$evidence,'placeholders'=>array_values($byKey),'by_key'=>$byKey];
    }

    private static function assertPrintifyBindings(array $area,int $blueprint,int $provider,array $variantIds): void
    {
        $bindings=$area['_catalog_bindings']??null;if(!is_array($bindings)||$bindings===[])throw new \InvalidArgumentException('non-draft Printify geometry requires exact catalog placeholder bindings');$seen=[];
        $environment=null;foreach($bindings as $binding){if(!is_array($binding)||($binding['schema']??null)!=='printify-placeholder-binding-v1'||($binding['provider']??null)!=='printify')throw new \InvalidArgumentException('Printify catalog placeholder binding is invalid');foreach(['environment','endpoint_path','observed_at','source_revision'] as $field)if(!is_string($binding[$field]??null)||$binding[$field]==='')throw new \InvalidArgumentException('Printify placeholder provenance is incomplete');if($environment!==null&&$environment!==$binding['environment'])throw new \InvalidArgumentException('selected Printify variants must share an environment');$environment=$binding['environment'];foreach(['blueprint_id','provider_id','variant_id'] as $field)if(!is_int($binding[$field]??null)||$binding[$field]<1)throw new \InvalidArgumentException('Printify placeholder identity is invalid');if($binding['blueprint_id']!==$blueprint||$binding['provider_id']!==$provider)throw new \InvalidArgumentException('Printify placeholder binding identity does not match template');if(!self::isSha256($binding['response_sha256']??null)||!self::isSha256($binding['variant_sha256']??null)||!self::isSha256($binding['placeholder_sha256']??null))throw new \InvalidArgumentException('Printify placeholder digest is invalid');$expectedPath="/v1/catalog/blueprints/{$blueprint}/print_providers/{$provider}/variants.json";if($binding['endpoint_path']!==$expectedPath)throw new \InvalidArgumentException('Printify placeholder endpoint does not match supplier identity');$placeholder=['position'=>$area['position'],'decoration_method'=>$area['decoration_method'],'width'=>$area['width_px'],'height'=>$area['height_px']];if(!hash_equals((string)$binding['placeholder_sha256'],hash('sha256',self::json($placeholder))))throw new \InvalidArgumentException('Printify placeholder digest does not match template geometry');$id=$binding['variant_id'];if(isset($seen[$id]))throw new \InvalidArgumentException('duplicate Printify variant placeholder binding');$seen[$id]=true;}
        $boundIds=array_map('intval',array_keys($seen));sort($boundIds,SORT_NUMERIC);if($boundIds!==$variantIds)throw new \InvalidArgumentException('Printify placeholder variants must exactly match the template variant set');
    }

    private static function isSha256(mixed $value): bool {return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}
    private static function json(array $value): string {$encoded=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($encoded))throw new \InvalidArgumentException('Printify placeholder cannot be encoded');return $encoded;}

    /** Preserve provider geometry without inventing a provider-specific schema or approval. */
    private static function geometryEvidence(mixed $value,int $depth=0): mixed
    {
        if($depth>8)throw new \InvalidArgumentException('geometry evidence exceeds safe nesting depth');
        if(is_array($value)){
            if(count($value)>256)throw new \InvalidArgumentException('geometry evidence exceeds safe collection size');
            $out=[];
            foreach($value as $key=>$item){
                if(is_string($key)&&(strlen($key)>128||preg_match('//u',$key)!==1))throw new \InvalidArgumentException('geometry evidence key is invalid');
                $out[$key]=self::geometryEvidence($item,$depth+1);
            }
            if(!array_is_list($out))ksort($out,SORT_STRING);
            return $out;
        }
        if(is_string($value)){
            if(strlen($value)>4096||preg_match('//u',$value)!==1)throw new \InvalidArgumentException('geometry evidence string is invalid');
            return $value;
        }
        if(is_float($value)&&!is_finite($value))throw new \InvalidArgumentException('geometry evidence number must be finite');
        if($value===null||is_bool($value)||is_int($value)||is_float($value))return $value;
        throw new \InvalidArgumentException('geometry evidence must contain JSON values');
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
