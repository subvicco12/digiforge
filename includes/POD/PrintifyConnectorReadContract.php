<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Fixed GET descriptions; preparation grants no execution authority. */
final class PrintifyConnectorReadContract
{
    public static function prepare(string $operation, string $providerShop, array $input = []): array
    {
        self::positive($providerShop);
        $keys = match ($operation) {
            'SHOPS_READ','BLUEPRINTS_READ','PROVIDERS_READ'=>[],
            'BLUEPRINT_READ'=>['blueprint_id'], 'PROVIDER_READ'=>['provider_id'],
            'VARIANTS_READ'=>['blueprint_id','provider_id'],
            'SHIPPING_READ'=>['blueprint_id','provider_id','shipping_method'],
            'PRODUCTS_READ','ORDERS_READ','UPLOADS_READ'=>['page','limit'],
            'PRODUCT_READ'=>['product_id'], 'ORDER_READ'=>['order_id'], 'UPLOAD_READ'=>['upload_id'],
            default=>throw new \InvalidArgumentException('Unsupported connector read operation.'),
        };
        if (array_diff(array_keys($input),$keys)) throw new \InvalidArgumentException('Unsupported connector read input.');
        $root='/v1/shops/'.$providerShop;
        $endpoint=match($operation) {
            'SHOPS_READ'=>'/v1/shops.json',
            'BLUEPRINTS_READ'=>'/v1/catalog/blueprints.json',
            'BLUEPRINT_READ'=>'/v1/catalog/blueprints/'.self::positive($input['blueprint_id']??null).'.json',
            'PROVIDERS_READ'=>'/v1/catalog/print_providers.json',
            'PROVIDER_READ'=>'/v1/catalog/print_providers/'.self::positive($input['provider_id']??null).'.json',
            'VARIANTS_READ'=>'/v1/catalog/blueprints/'.self::positive($input['blueprint_id']??null).'/print_providers/'.self::positive($input['provider_id']??null).'/variants.json',
            'SHIPPING_READ'=>'/v2/catalog/blueprints/'.self::positive($input['blueprint_id']??null).'/print_providers/'.self::positive($input['provider_id']??null).'/shipping'.self::shipping($input).'.json',
            'PRODUCTS_READ'=>$root.'/products.json'.self::pagination($input),
            'ORDERS_READ'=>$root.'/orders.json'.self::pagination($input),
            'UPLOADS_READ'=>'/v1/uploads.json'.self::pagination($input),
            'PRODUCT_READ'=>$root.'/products/'.self::resource($input['product_id']??null).'.json',
            'ORDER_READ'=>$root.'/orders/'.self::resource($input['order_id']??null).'.json',
            'UPLOAD_READ'=>'/v1/uploads/'.self::resource($input['upload_id']??null).'.json',
        };
        $material=['operation'=>$operation,'method'=>'GET','endpoint'=>$endpoint,'api_version'=>$operation==='SHIPPING_READ'?'v2':'v1','provider_shop_id'=>$providerShop];
        return $material+['fingerprint'=>hash('sha256',json_encode($material,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
    }

    /** Rebuild from the endpoint and compare every field, rejecting added fields. */
    public static function validate(array $request): array
    {
        if (array_diff(array_keys($request),['operation','method','endpoint','api_version','provider_shop_id','fingerprint']) || count($request)!==6) throw new \InvalidArgumentException('Invalid connector read request.');
        $endpoint=$request['endpoint']??null;
        if (!is_string($endpoint) || !is_string($request['operation']??null) || !is_string($request['provider_shop_id']??null)) throw new \InvalidArgumentException('Invalid connector read request.');
        $input=[];
        $op=$request['operation'];
        if (in_array($op,['PRODUCTS_READ','ORDERS_READ','UPLOADS_READ'],true)) {
            if (!preg_match('/\?page=([1-3])&limit=([1-9]|10)$/D',$endpoint,$m)) throw new \InvalidArgumentException('Invalid pagination.');
            $input=['page'=>(int)$m[1],'limit'=>(int)$m[2]];
        } elseif (in_array($op,['BLUEPRINT_READ','PROVIDER_READ','PRODUCT_READ','ORDER_READ','UPLOAD_READ'],true)) {
            if (!preg_match('#/([A-Za-z0-9_-]+)\.json$#D',$endpoint,$m)) throw new \InvalidArgumentException('Invalid resource.');
            $key=match($op){'BLUEPRINT_READ'=>'blueprint_id','PROVIDER_READ'=>'provider_id','PRODUCT_READ'=>'product_id','ORDER_READ'=>'order_id','UPLOAD_READ'=>'upload_id'};
            $input[$key]=$m[1];
        } elseif (in_array($op,['VARIANTS_READ','SHIPPING_READ'],true)) {
            if (!preg_match('#/blueprints/([1-9][0-9]*)/print_providers/([1-9][0-9]*)/(?:variants|shipping(?:/(standard|priority|express|economy))?)\.json$#D',$endpoint,$m)) throw new \InvalidArgumentException('Invalid catalog selection.');
            $input=['blueprint_id'=>$m[1],'provider_id'=>$m[2]];
            if (isset($m[3]) && $m[3]!=='') $input['shipping_method']=$m[3];
        }
        $expected=self::prepare($op,$request['provider_shop_id'],$input);
        foreach ($expected as $key=>$value) if (!isset($request[$key]) || $request[$key]!==$value) throw new \InvalidArgumentException('Connector read request differs from its contract.');
        return $expected;
    }
    /** Compare decoded values and keys, independently of JSON escaping rules. */
    public static function containsProtectedMaterial(mixed $value,string $token):bool
    {
        if(is_string($value))return str_contains($value,$token);
        if(is_array($value))foreach($value as $key=>$item)if((is_string($key)&&str_contains($key,$token))||self::containsProtectedMaterial($item,$token))return true;
        return false;
    }
    private static function positive(mixed $id): string { if ((!is_string($id)&&!is_int($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id)) throw new \InvalidArgumentException('Canonical positive identity required.'); return (string)$id; }
    private static function resource(mixed $id): string { if (!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id)) throw new \InvalidArgumentException('Canonical resource identity required.'); return $id; }
    private static function pagination(array $input): string { $page=$input['page']??1;$limit=$input['limit']??10; if (!is_int($page)||$page<1||$page>3||!is_int($limit)||$limit<1||$limit>10) throw new \InvalidArgumentException('Bounded pagination required.'); return '?page='.$page.'&limit='.$limit; }
    private static function shipping(array $input): string { if (!array_key_exists('shipping_method',$input)) return ''; $method=$input['shipping_method']; if (!is_string($method)||!in_array($method,['standard','priority','express','economy'],true)) throw new \InvalidArgumentException('Unsupported shipping method.'); return '/'.$method; }
}
