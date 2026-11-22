<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Pure catalog receipt projection. Only the governed gateway may supply operational receipts. */
final class PrintifyResearchMapper
{
    public static function endpoint(array $request): string
    {
        $kind=$request['kind']??'';
        $keys=match($kind){'blueprint','providers','size_guide'=>['kind','blueprint_id'],'variants','shipping'=>['kind','blueprint_id','provider_id'],'variant'=>['kind','blueprint_id','provider_id','variant_id'],'shipping_method'=>['kind','blueprint_id','provider_id','method'],default=>throw new \InvalidArgumentException('Unsupported catalog GET evidence.')};
        if(count($request)!==count($keys)||array_diff(array_keys($request),$keys))throw new \InvalidArgumentException('Exact catalog request fields required.');
        foreach($request as $key=>$value)if(!in_array($key,['kind','method'],true)&&(!is_int($value)||$value<1||$value>2147483647))throw new \InvalidArgumentException('Positive bounded identifiers required.');
        $base='/v1/catalog/blueprints/'.$request['blueprint_id'];
        return match($kind){'blueprint'=>$base.'.json','providers'=>$base.'/print_providers.json','size_guide'=>$base.'/size_guide.json','variants','variant'=>$base.'/print_providers/'.$request['provider_id'].'/variants.json','shipping'=>'/v2/catalog/blueprints/'.$request['blueprint_id'].'/print_providers/'.$request['provider_id'].'/shipping.json','shipping_method'=>in_array($request['method'],['standard','priority','express','economy'],true)?'/v2/catalog/blueprints/'.$request['blueprint_id'].'/print_providers/'.$request['provider_id'].'/shipping/'.$request['method'].'.json':throw new \InvalidArgumentException('Unsupported shipping method.')};
    }

    public static function map(array $receipt): array
    {
        $r=$receipt['request']??[];
        if(!is_array($r)||($receipt['endpoint_path']??null)!==self::endpoint($r)||!is_array($receipt['data']??null)||($receipt['scope']['product_program']??null)!=='PERSONALIZED_POD')throw new \InvalidArgumentException('Exact scoped catalog receipt required.');
        foreach(['sha256','grant_sha256','request_sha256','response_sha256'] as $key)if(!is_string($receipt[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$receipt[$key]))throw new \InvalidArgumentException('Receipt provenance hashes required.');
        if(!is_int($receipt['integration_id']??null)||$receipt['integration_id']<1||!is_string($receipt['environment']??null)||!is_string($receipt['observed_at']??null)||strtotime($receipt['observed_at'])===false)throw new \InvalidArgumentException('Observation identity required.');
        self::safe($receipt['data']);
        $meta=['Blueprint ID'=>(string)$r['blueprint_id'],'Provider ID'=>isset($r['provider_id'])?(string)$r['provider_id']:null,'_source_id'=>'printify:'.$receipt['sha256'],'_receipt_sha256'=>$receipt['sha256'],'_grant_sha256'=>$receipt['grant_sha256'],'_request_sha256'=>$receipt['request_sha256'],'_response_sha256'=>$receipt['response_sha256'],'_endpoint'=>$receipt['endpoint_path'],'_observed_at'=>$receipt['observed_at'],'_integration_id'=>(string)$receipt['integration_id'],'_environment'=>$receipt['environment'],'_approval_status'=>'UNREVIEWED','_source_sheet'=>'Printify catalog API','Freshness'=>'DATED_OBSERVATION_REVALIDATE_BEFORE_USE'];
        $out=array_fill_keys(['blueprints','providers','variants','print_areas','size_guides','shipping','gaps'],[]);$data=$receipt['data'];
        $gap=static function(string $code,string $detail)use(&$out,$meta):void{$out['gaps'][]=$meta+['Gap'=>$code,'Evidence'=>$detail,'Status'=>'OPEN'];};
        switch($r['kind']){
            case 'blueprint':
                if(($data['id']??null)!==$r['blueprint_id'])throw new \InvalidArgumentException('Blueprint identity mismatch.');
                $out['blueprints'][]=$meta+['Title'=>self::cell($data['title']??null),'Brand'=>self::cell($data['brand']??null),'Model'=>self::cell($data['model']??null)];
                if(isset($data['size_guide'])&&is_array($data['size_guide']))self::sizes($data['size_guide'],$meta,$out);else $gap('SIZE_GUIDE_MISSING','A separately scoped size_guide GET or retained source import is required.');break;
            case 'providers':
                self::records($data);
                foreach($data as $provider)$out['providers'][]=array_replace($meta,['Provider ID'=>(string)$provider['id'],'Provider Name'=>self::cell($provider['title']??null),'Location'=>self::cell($provider['location']??null),'Production Time'=>self::cell($provider['production_time']??null),'Availability'=>'UNVERIFIED']);
                if($data===[])$gap('NO_PROVIDERS_RETURNED','An empty response is an observation, not supplier unavailability certification.');break;
            case 'variant': case 'variants':
                $variants=$r['kind']==='variant'?[$data]:($data['variants']??null);if(!is_array($variants))throw new \InvalidArgumentException('Variant list required.');self::records($variants);
                foreach($variants as $variant){
                    if(isset($r['variant_id'])&&$variant['id']!==$r['variant_id'])throw new \InvalidArgumentException('Variant identity mismatch.');
                    $v=$meta+['Variant ID'=>(string)$variant['id']];
                    $out['variants'][]=$v+['Title'=>self::cell($variant['title']??null),'SKU'=>self::cell($variant['sku']??null),'Available'=>isset($variant['is_available'])&&is_bool($variant['is_available'])?($variant['is_available']?'TRUE':'FALSE'):'UNKNOWN','Options'=>self::cell($variant['options']??null),'Raw Cost Evidence'=>self::cell($variant['cost']??null),'Currency'=>self::cell($variant['currency']??null),'Production Cost'=>null,'Tax Amount'=>null,'Raw Tax Evidence'=>self::cell($variant['tax']??null),'Cost Status'=>'UNVERIFIED_UNITS_AND_BILLING'];
                    $areas=$variant['placeholders']??[];if(!is_array($areas)||!array_is_list($areas)||count($areas)>100)throw new \InvalidArgumentException('Bounded print areas required.');
                    foreach($areas as $area){if(!is_array($area))throw new \InvalidArgumentException('Print area object required.');$out['print_areas'][]=$v+['Print Area'=>self::cell($area['position']??null),'Width PX'=>self::positiveCell($area['width']??null),'Height PX'=>self::positiveCell($area['height']??null),'DPI'=>self::cell($area['dpi']??null),'Bleed'=>self::cell($area['bleed']??null),'Safe Zone'=>self::cell($area['safe_zone']??null),'Decoration'=>self::cell($data['decoration_method']??null),'Geometry Status'=>'PIXEL_DIMENSIONS_ONLY','Production Certified'=>'FALSE'];}
                    $gap('COST_TAX_AND_PHYSICAL_GEOMETRY_UNVERIFIED','Catalog variant evidence is not a production price, tax calculation or certified physical template.');
                }break;
            case 'size_guide':self::sizes($data,$meta,$out);if($out['size_guides']===[])$gap('SIZE_GUIDE_EMPTY','Provider returned no size-guide measurements.');break;
            case 'shipping': case 'shipping_method':
                $resources=$data['data']??null;if(!is_array($resources)||!array_is_list($resources)||count($resources)>1000)throw new \InvalidArgumentException('Bounded shipping resources required.');
                foreach($resources as $resource){$a=$resource['attributes']??null;if(!is_array($a))throw new \InvalidArgumentException('Shipping attributes required.');
                    if($r['kind']==='shipping'){$method=$a['name']??null;if(!in_array($method,['standard','priority','express','economy'],true))throw new \InvalidArgumentException('Unknown shipping method.');$out['shipping'][]=$meta+['Service'=>$method,'Country / Zone'=>null,'First Item Amount'=>null,'Additional Item Amount'=>null,'Currency'=>null,'Rate Units'=>null,'Evidence'=>'SERVICE_INDEX_ONLY'];}
                    else{$method=$r['method'];if(($a['shippingType']??null)!==$method)throw new \InvalidArgumentException('Shipping method mismatch.');$country=$a['country']['code']??null;if(!is_string($country)||!preg_match('/^(?:[A-Z]{2}|REST_OF_THE_WORLD)$/D',$country))throw new \InvalidArgumentException('Shipping country required.');
                        $first=$a['shippingCost']['firstItem']??null;$additional=$a['shippingCost']['additionalItems']??null;
                        foreach([$first,$additional] as $money)if(!is_array($money)||!is_int($money['amount']??null)||$money['amount']<0||!preg_match('/^[A-Z]{3}$/D',$money['currency']??''))throw new \InvalidArgumentException('Explicit shipping amounts and currencies required.');
                        if($first['currency']!==$additional['currency'])throw new \InvalidArgumentException('Shipping currency mismatch.');
                        $out['shipping'][]=$meta+['Variant ID'=>self::positiveCell($a['variantId']??null),'Service'=>$method,'Country / Zone'=>$country,'First Item Amount'=>(string)$first['amount'],'Additional Item Amount'=>(string)$additional['amount'],'Currency'=>$first['currency'],'Rate Units'=>'RAW_PROVIDER_AMOUNT_UNITS_UNVERIFIED','Handling From'=>self::cell($a['handlingTime']['from']??null),'Handling To'=>self::cell($a['handlingTime']['to']??null),'Tax Amount'=>null,'Evidence'=>'PROVIDER_QUOTE_UNVERIFIED'];}
                }
                if($r['kind']==='shipping')$gap('DESTINATION_RATES_MISSING','Service index links are not followed. Each method requires explicit grant scope.');
                elseif($resources===[])$gap('DESTINATION_QUOTES_EMPTY','No destination quotes were returned for this exact method; rates and destination coverage remain unknown.');break;
        }
        return $out;
    }
    public static function append(array $packet,array $receipt): array
    {
        $packet=ProductIntelligence::validate($packet);$sections=self::map($receipt);$id='printify:'.$receipt['sha256'];
        foreach($packet['sources'] as $source)if($source['id']===$id)return $packet;
        $packet['sources'][]=['id'=>$id,'name'=>'WordPress Printify catalog receipt '.$receipt['request']['kind'],'sha256'=>$receipt['response_sha256'],'modified_at'=>$receipt['observed_at']];
        foreach($sections as $key=>$rows){if($rows===[])continue;
            if(!isset($packet['sections'][$key]))$packet['sections'][$key]=['source_id'=>$id,'sheet'=>'Printify catalog API','rows'=>[]];
            $binding=&$packet['sections'][$key];
            foreach($binding['rows'] as &$existing)if(!isset($existing['_source_id']))$existing['_source_id']=$binding['source_id'];unset($existing);
            $binding['rows']=array_merge($binding['rows'],$rows);$binding['rows_sha256']=ProductIntelligence::rowsHash($binding['rows']);unset($binding);
        }
        return ProductIntelligence::validate($packet);
    }
    private static function sizes(array $data,array $meta,array &$out): void
    {
        $sizes=$data['sizes']??null;$types=$data['types']??null;if(!is_array($sizes)||!array_is_list($sizes)||count($sizes)>100||!is_array($types)||!array_is_list($types)||count($types)>100)throw new \InvalidArgumentException('Bounded size-guide schema required.');
        foreach($types as $type){if(!is_array($type)||!is_array($type['ranges']??null)||count($type['ranges'])!==count($sizes))throw new \InvalidArgumentException('Size ranges must match size order.');foreach($sizes as $i=>$size){$range=$type['ranges'][$i];if(!is_array($range))throw new \InvalidArgumentException('Size range required.');$out['size_guides'][]=$meta+['Size / Variant'=>self::cell($size),'Measurement'=>self::cell($type['name']??null),'Unit'=>self::cell($type['units']??null),'Value'=>self::cell($range['from']??null),'Range To'=>self::cell($range['to']??null),'Evidence'=>'PROVIDER_SIZE_GUIDE_UNVERIFIED'];}}
    }
    private static function records(array $rows): void {if(!array_is_list($rows)||count($rows)>1000)throw new \InvalidArgumentException('Bounded record list required.');$seen=[];foreach($rows as $row){$id=$row['id']??null;if(!is_int($id)||$id<1||isset($seen[$id]))throw new \InvalidArgumentException('Unique positive identity required.');$seen[$id]=true;}}
    private static function cell(mixed $value): ?string {if($value===null||$value==='')return null;if(is_string($value))return $value;return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function positiveCell(mixed $value): ?string {return is_int($value)&&$value>0?(string)$value:null;}
    private static function safe(array $data,int $depth=0): void {if($depth>20)throw new \InvalidArgumentException('Evidence nesting exceeded.');foreach($data as $key=>$value){if(is_string($key)&&preg_match('/(password|secret|token|authorization|api.?key|credential)/i',$key))throw new \InvalidArgumentException('Protected material rejected.');if(is_array($value))self::safe($value,$depth+1);}}
}
