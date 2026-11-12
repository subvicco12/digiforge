<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Offline, bounded request descriptions for documented Printify product operations. No transport or authority. */
final class PrintifyProductRequest
{
    public static function prepare(string $operation, string $shop, array $input): array
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $shop) !== 1) self::invalid();
        $prefix = '/v1/shops/'.$shop.'/products';
        $body = [];
        $method = 'GET';
        $scope = 'products.read';
        switch ($operation) {
            case 'IMAGE_UPLOAD':
                self::keys($input, ['file_name','contents']);
                if (!is_string($input['file_name']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png$/D', $input['file_name']) !== 1 || str_contains($input['file_name'], '..')) self::invalid();
                if (!is_string($input['contents']) || strlen($input['contents']) > 2796204) self::invalid();
                $bytes = base64_decode($input['contents'], true);
                if (!is_string($bytes) || strlen($bytes) > 2097152 || base64_encode($bytes) !== $input['contents']) self::invalid();
                // Shared CRC/chunk/decompression validation accepts only bounded actual PNG bytes, never URLs.
                require_once __DIR__.'/../Research/DesignFactoryContract.php';
                \DigiForge\Research\DesignFactoryContract::mockup($bytes);
                $body = $input; $method = 'POST'; $endpoint = '/v1/uploads/images.json'; $scope = 'uploads.write';
                break;
            case 'IMAGE_READ':
                self::keys($input, ['image_id']); self::id($input['image_id']);
                $endpoint = '/v1/uploads/'.$input['image_id'].'.json'; $scope = 'uploads.read';
                break;
            case 'PRODUCT_CREATE':
                self::keys($input, ['title','description','blueprint_id','print_provider_id','variants','print_areas']);
                self::product($input); $body = $input; $method = 'POST'; $endpoint = $prefix.'.json'; $scope = 'products.write';
                break;
            case 'PRODUCT_UPDATE':
                self::keys($input, ['product_id'], ['title','description','blueprint_id','print_provider_id','variants','print_areas','known_variant_ids']);
                self::id($input['product_id']); $body = $input; unset($body['product_id'], $body['known_variant_ids']);
                if ($body === []) self::invalid();
                self::product($body);
                if (isset($input['variants'])) {
                    if (!isset($input['known_variant_ids'])) self::invalid();
                    self::ids($input['known_variant_ids']);
                    $actual = array_column($input['variants'], 'id'); $known = $input['known_variant_ids']; sort($actual); sort($known);
                    if ($actual !== $known) self::invalid();
                } elseif (array_key_exists('known_variant_ids', $input)) self::invalid();
                // A geometry update must carry complete variants too, so all area IDs can be checked.
                if (isset($body['print_areas']) && !isset($body['variants'])) self::invalid();
                $method = 'PUT'; $endpoint = $prefix.'/'.$input['product_id'].'.json'; $scope = 'products.write';
                break;
            case 'PRODUCT_READ':
            case 'PERSONALIZATION_OPTIONS':
                self::keys($input, ['product_id']); self::id($input['product_id']);
                $endpoint = $prefix.'/'.$input['product_id'].($operation === 'PRODUCT_READ' ? '.json' : '/personalization_options.json');
                break;
            case 'PERSONALIZATION_CONFIGURE':
                self::keys($input, ['product_id','variant_id','items','require_all_personalization_fields']); self::id($input['product_id']); self::positive($input['variant_id']); self::items($input['items']);
                if ($input['require_all_personalization_fields'] !== true) self::invalid();
                $body = $input; unset($body['product_id']); $method = 'POST'; $endpoint = $prefix.'/'.$input['product_id'].'/personalization.json'; $scope = 'products.write';
                break;
            case 'PREVIEW_CREATE':
                self::keys($input, ['product_id','external_id','variant_ids','personalization','format']); self::id($input['product_id']); self::id($input['external_id']); self::ids($input['variant_ids']); self::items($input['personalization']);
                if (!in_array($input['format'], ['png','jpeg'], true)) self::invalid();
                $body = $input; unset($body['product_id']); $method = 'POST'; $endpoint = $prefix.'/'.$input['product_id'].'/personalization_previews.json'; $scope = 'products.write';
                break;
            case 'PREVIEW_READ':
                self::keys($input, ['product_id','task_id']); self::id($input['product_id']); self::id($input['task_id']);
                $endpoint = $prefix.'/'.$input['product_id'].'/personalization_previews/tasks/'.$input['task_id'].'.json';
                break;
            default:
                self::invalid();
        }
        $material = ['operation'=>$operation,'method'=>$method,'endpoint'=>$endpoint,'body'=>$body,'required_scope'=>$scope];
        try { $json = json_encode(self::canonical($material), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); } catch (\JsonException) { self::invalid(); }
        if (strlen($json) > 3000000 || ($operation !== 'IMAGE_UPLOAD' && strlen($json) > 262144)) self::invalid();
        return $material + ['fingerprint'=>hash('sha256', $json),'external_execution_authorized'=>false];
    }
    /** Detect request-envelope changes only; a matching digest never confers execution authority. */
    public static function intact(array $request): bool
    {
        $keys=['operation','method','endpoint','body','required_scope','fingerprint','external_execution_authorized'];
        if (array_diff($keys,array_keys($request)) || array_diff(array_keys($request),$keys)) return false;
        if ($request['external_execution_authorized']!==false || !is_string($request['fingerprint']) || preg_match('/^[a-f0-9]{64}$/D',$request['fingerprint'])!==1 || !is_array($request['body'])) return false;
        foreach (['operation','method','endpoint','required_scope'] as $key) if (!is_string($request[$key]) || $request[$key]==='') return false;
        $material=[]; foreach (['operation','method','endpoint','body','required_scope'] as $key) $material[$key]=$request[$key];
        try { $json=json_encode(self::canonical($material),JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION); } catch (\JsonException) { return false; }
        if (strlen($json)>3000000 || ($request['operation']!=='IMAGE_UPLOAD' && strlen($json)>262144)) return false;
        return hash_equals($request['fingerprint'],hash('sha256',$json));
    }
    private static function product(array $body): void
    {
        foreach (['title'=>200,'description'=>10000] as $key=>$max) if (array_key_exists($key,$body)) self::text($body[$key],$max);
        foreach (['blueprint_id','print_provider_id'] as $key) if (array_key_exists($key,$body)) self::positive($body[$key]);
        $variantIds = [];
        if (array_key_exists('variants',$body)) {
            self::list($body['variants'],1,1000);
            foreach ($body['variants'] as $variant) {
                if (!is_array($variant)) self::invalid(); self::keys($variant,['id','price','is_enabled']); self::positive($variant['id']);
                if (in_array($variant['id'],$variantIds,true) || !is_int($variant['price']) || $variant['price'] < 0 || $variant['price'] > 100000000 || !is_bool($variant['is_enabled']) || ($variant['is_enabled'] && $variant['price'] === 0)) self::invalid();
                $variantIds[] = $variant['id'];
            }
        }
        if (!array_key_exists('print_areas',$body)) return;
        self::list($body['print_areas'],1,100);
        foreach ($body['print_areas'] as $area) {
            if (!is_array($area)) self::invalid(); self::keys($area,['variant_ids','placeholders']); self::ids($area['variant_ids']);
            foreach ($area['variant_ids'] as $id) if (!in_array($id,$variantIds,true)) self::invalid();
            self::list($area['placeholders'],1,20); $positions = [];
            foreach ($area['placeholders'] as $placeholder) {
                if (!is_array($placeholder)) self::invalid(); self::keys($placeholder,['position','images']); self::id($placeholder['position']);
                if (in_array($placeholder['position'],$positions,true)) self::invalid(); $positions[]=$placeholder['position'];
                self::list($placeholder['images'],1,20);
                foreach ($placeholder['images'] as $image) {
                    if (!is_array($image)) self::invalid(); self::keys($image,['id','x','y','scale','angle']); self::id($image['id']);
                    self::number($image['x'],0,1); self::number($image['y'],0,1); self::number($image['scale'],0.000001,10); self::number($image['angle'],0,360);
                }
            }
        }
    }
    private static function items(mixed $items): void
    {
        self::list($items,1,30); $fields=[];
        foreach ($items as $item) {
            if (!is_array($item)) self::invalid(); self::keys($item,['field_id','type','input']); self::id($item['field_id']);
            if (!in_array($item['type'],['textbox','image'],true) || in_array($item['field_id'],$fields,true) || !is_array($item['input'])) self::invalid();
            $fields[]=$item['field_id'];
            if ($item['type']==='textbox') {
                self::keys($item['input'],['text']); self::text($item['input']['text'],2000);
            } else {
                // Official OpenAPI personalizationConfigItemInput.image_url. The workflow must
                // additionally bind this URL to a same-integration verified upload/read receipt.
                self::keys($item['input'],['image_url']); self::imageUrl($item['input']['image_url']);
            }
        }
    }
    private static function imageUrl(mixed $value): void
    {
        if (!is_string($value) || strlen($value)>2000 || preg_match('/[\x00-\x20\x7F\\\\]/',$value) || filter_var($value,FILTER_VALIDATE_URL)===false) self::invalid();
        $parts=parse_url($value);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' || !isset($parts['host'])) self::invalid();
        foreach (['user','pass','port','query','fragment'] as $key) if (array_key_exists($key,$parts)) self::invalid();
        $host=strtolower($parts['host']);
        // Require a public-style DNS name; reject IP literals, local names and numeric hosts
        // without making DNS or network requests from this offline contract.
        if (strlen($host)>253 || filter_var($host,FILTER_VALIDATE_IP)!==false || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)!==1 || preg_match('/(?:^|\.)(?:localhost|local|internal|lan|home)$/D',$host)) self::invalid();
    }
    private static function keys(array $input,array $required,array $optional=[]): void
    {
        if (array_diff($required,array_keys($input)) || array_diff(array_keys($input),array_merge($required,$optional))) self::invalid();
    }
    private static function id(mixed $value): void { if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$value)!==1) self::invalid(); }
    private static function positive(mixed $value): void { if (!is_int($value) || $value<1 || $value>2147483647) self::invalid(); }
    private static function ids(mixed $value): void { self::list($value,1,1000); foreach ($value as $id) self::positive($id); if (count(array_unique($value,SORT_NUMERIC))!==count($value)) self::invalid(); }
    private static function list(mixed $value,int $min,int $max): void { if (!is_array($value) || !array_is_list($value) || count($value)<$min || count($value)>$max) self::invalid(); }
    private static function text(mixed $value,int $max): void { if (!is_string($value) || trim($value)==='' || strlen($value)>$max || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) self::invalid(); }
    private static function number(mixed $value,float $min,float $max): void { if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<$min || $value>$max) self::invalid(); }
    private static function canonical(array $value): array { foreach ($value as $key=>$item) if (is_array($item)) $value[$key]=self::canonical($item); if (!array_is_list($value)) ksort($value); return $value; }
    private static function invalid(): never { throw new \InvalidArgumentException('A bounded documented offline Printify product request with exact identifiers, complete variants, explicit placement and supported native personalization inputs is required.'); }
}
