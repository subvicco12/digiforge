<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Read-only response projection; provider pagination links are never followed. */
final class PrintifyApiReadEvidence
{
    public static function project(string $operation, array $data, string $shop, array $input): array
    {
        if ($operation === 'PRODUCT_GPSR_READ') {
            PrintifyApiRequest::prepare($operation, $shop, $input);
            return self::gpsr($data, $shop, $input);
        }
        if ($operation === 'PRODUCT_READ') {
            PrintifyApiRequest::prepare($operation, $shop, $input);
            return self::product($data, $shop, $input);
        }
        if ($operation === 'PRODUCTS_READ') {
            PrintifyApiRequest::prepare($operation, $shop, $input);
            self::bounded($data);
            $page=$input['page']??1; $limit=$input['limit']??10;
            if (!is_array($data['data']??null) || !array_is_list($data['data']) || count($data['data'])>$limit || ($data['current_page']??null)!==$page || ($data['per_page']??null)!==$limit || !is_int($data['last_page']??null) || $data['last_page']<$page || $data['last_page']>2147483647) { self::bad(); }
            $rows=[]; $seen=[];
            foreach ($data['data'] as $row) { if (!is_array($row)) self::bad(); $product=self::product($row,$shop,null); if (isset($seen[$product['id']])) self::bad(); $seen[$product['id']]=true; $rows[]=$product; }
            return ['data'=>$rows,'current_page'=>$page,'last_page'=>$data['last_page'],'per_page'=>$limit,'next_page'=>$page<$data['last_page']?['page'=>$page+1,'limit'=>$limit]:null,'pagination_followed'=>false];
        }
        if ($operation === 'ORDER_READ') { self::shop($data,$shop); return PrintifyOrderEvidence::project($data, (string)$input['order_id']); }
        if ($operation === 'ORDERS_READ') {
            if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || count($data['data']) > 10 || !is_int($data['current_page'] ?? null) || $data['current_page'] !== (int)($input['page'] ?? 1)) { throw new \InvalidArgumentException('Invalid bounded order page.'); }
            $rows = []; $seen = [];
            foreach ($data['data'] as $order) {
                if (!is_array($order) || !is_string($order['id'] ?? null) || isset($seen[$order['id']])) { throw new \InvalidArgumentException('Invalid order identity.'); }
                self::shop($order,$shop);
                $seen[$order['id']] = true;
                $rows[] = PrintifyOrderEvidence::project($order, $order['id']);
            }
            $last=$data['last_page']??null;$per=$data['per_page']??null;if($last!==null&&(!is_int($last)||$last<$data['current_page']||$last>2147483647))self::bad();if($per!==null&&(!is_int($per)||$per<1||$per>10||$per!==(int)($input['limit']??10)))self::bad();
            return ['data'=>$rows,'current_page'=>$data['current_page'],'last_page'=>$last,'per_page'=>$per,'next_page'=>$last!==null&&$data['current_page']<$last?['page'=>$data['current_page']+1,'limit'=>$per??10]:null,'pagination_followed'=>false];
        }
        if ($operation === 'SHOPS_READ') {
            self::rows($data, 100); $matches = []; $seen = [];
            foreach ($data as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || isset($seen[$row['id']]) || !is_string($row['title'] ?? null) || strlen($row['title']) > 300 || !is_string($row['sales_channel'] ?? null) || strlen($row['sales_channel']) > 100) { throw new \InvalidArgumentException('Invalid shop evidence.'); }
                $seen[$row['id']] = true;
                if ((string)$row['id'] === $shop) { $matches[] = array_intersect_key($row, array_flip(['id','title','sales_channel'])); }
            }
            if (count($matches) !== 1) { throw new \InvalidArgumentException('Configured shop absent from account.'); }
            $ids=array_keys($seen);sort($ids,SORT_NUMERIC);return ['shops'=>$matches,'account_shop_set_sha256'=>hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR)),'other_shop_data_disclosed'=>false];
        }
        if ($operation === 'PROVIDERS_READ' || $operation === 'PROVIDER_READ') {
            $rows = $operation === 'PROVIDER_READ' ? [$data] : $data; self::rows($rows, 1000); $out = []; $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || isset($seen[$row['id']]) || !is_string($row['title'] ?? null) || strlen($row['title']) > 300) { throw new \InvalidArgumentException('Malformed provider evidence.'); }
                if ($operation === 'PROVIDER_READ' && $row['id'] !== (int)$input['provider_id']) { throw new \InvalidArgumentException('Provider identity mismatch.'); }
                $seen[$row['id']] = true; $out[] = ['id'=>$row['id'],'title'=>$row['title'],'regional_shipping_verified'=>false];
            }
            return ['providers'=>$out];
        }
        throw new \InvalidArgumentException('No admitted response projection for operation.');
    }
    public static function supported(string $operation): bool
    {
        return in_array($operation, ['PRODUCTS_READ','PRODUCT_READ','PRODUCT_GPSR_READ','SHOPS_READ','PROVIDERS_READ','PROVIDER_READ','ORDER_READ','ORDERS_READ'], true);
    }
    /** Source text only. GPSR does not contain response identities or certify compliance. */
    private static function gpsr(array $data, string $shop, array $input): array
    {
        self::bounded($data); self::rows($data, 100); $rows=[]; $seen=[];
        foreach ($data as $row) {
            if (!is_array($row) || count($row)!==2 || array_diff(array_keys($row), ['title','text'])) self::bad();
            foreach (['title'=>300,'text'=>10000] as $key=>$max) {
                $value=$row[$key]??null;
                if (!is_string($value) || strlen($value)>$max || preg_match('//u',$value)!==1 || preg_match('/[<>\\x00-\\x1f\\x7f]/',$value) || preg_match('~(?:https?://|www\\.|javascript:|data:|file:)~i',$value)) self::bad();
            }
            if ($row['title']==='' || isset($seen[$row['title']])) self::bad();
            $seen[$row['title']]=true; $rows[]=$row;
        }
        return ['provider_shop_id'=>$shop,'product_id'=>$input['product_id'],'blueprint_id'=>$input['blueprint_id'],'provider_id'=>$input['provider_id'],'variant_ids'=>$input['variant_ids'],'gpsr'=>$rows,'supplier_certified'=>null];
    }
    private static function product(array $data, string $shop, ?array $expected): array
    {
        self::bounded($data); self::identity($data['id']??null);
        if (($expected!==null && $data['id']!==$expected['product_id']) || (array_key_exists('shop_id',$data) && ((!is_int($data['shop_id']) && !is_string($data['shop_id'])) || (string)$data['shop_id']!==$shop)) || (isset($data['is_deleted']) && $data['is_deleted']!==false) || !empty($data['deleted_at'])) self::bad();
        foreach (['blueprint_id','print_provider_id'] as $key) self::positive($data[$key]??null);
        if ($expected!==null && ($data['blueprint_id']!==$expected['blueprint_id'] || $data['print_provider_id']!==$expected['provider_id'])) self::bad();
        if (!is_array($data['variants']??null)) self::bad(); self::rows($data['variants'],1000); if (empty($data['variants'])) self::bad();
        $variants=[];$ids=[];
        foreach ($data['variants'] as $v) { if (!is_array($v)) self::bad(); self::positive($v['id']??null); if (isset($ids[$v['id']]) || !is_int($v['price']??null) || $v['price']<0 || $v['price']>100000000 || !is_bool($v['is_enabled']??null)) self::bad(); $ids[$v['id']]=true; $variants[]=array_intersect_key($v,array_flip(['id','price','is_enabled'])); }
        if ($expected!==null) { $actual=array_keys($ids); $wanted=$expected['variant_ids']; sort($actual);sort($wanted); if ($actual!==$wanted) self::bad(); }
        if (!is_array($data['print_areas']??null)) self::bad(); self::rows($data['print_areas'],100);$areas=[];$assigned=[];
        foreach ($data['print_areas'] as $area) {
            if (!is_array($area)) self::bad(); $areaIds=self::variantIds($area['variant_ids']??null,$ids); foreach ($areaIds as $id) { if (isset($assigned[$id])) self::bad(); $assigned[$id]=true; }
            if (!is_array($area['placeholders']??null)) self::bad(); self::rows($area['placeholders'],20);$holders=[];$positions=[];
            foreach ($area['placeholders'] as $holder) {
                if (!is_array($holder)) self::bad(); self::identity($holder['position']??null);if(isset($positions[$holder['position']])) self::bad();$positions[$holder['position']]=true;
                if (!is_array($holder['images']??null)) self::bad();self::rows($holder['images'],20);$images=[];
                foreach ($holder['images'] as $image) { if(!is_array($image))self::bad(); self::identity($image['id']??null);foreach(['x'=>[0,1],'y'=>[0,1],'scale'=>[0.000001,10],'angle'=>[0,360]] as $key=>$range) { $v=$image[$key]??null;if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<$range[0]||$v>$range[1]) self::bad(); }$images[]=array_intersect_key($image,array_flip(['id','x','y','scale','angle'])); }
                $holders[]=['position'=>$holder['position'],'images'=>$images];
            }
            $areas[]=['variant_ids'=>$areaIds,'placeholders'=>$holders];
        }
        $images=$data['images']??[]; if (!is_array($images)) self::bad();self::rows($images,100);$projected=[];
        foreach ($images as $image) { if(!is_array($image))self::bad();self::url($image['src']??null);$item=['src'=>$image['src']];if(array_key_exists('variant_ids',$image))$item['variant_ids']=self::variantIds($image['variant_ids'],$ids);if(isset($image['position'])){self::identity($image['position']);$item['position']=$image['position'];}if(isset($image['is_default'])){if(!is_bool($image['is_default']))self::bad();$item['is_default']=$image['is_default'];}$projected[]=$item; }
        return ['id'=>$data['id'],'blueprint_id'=>$data['blueprint_id'],'print_provider_id'=>$data['print_provider_id'],'variants'=>$variants,'print_areas'=>$areas,'images'=>$projected,'supplier_certified'=>false];
    }
    private static function variantIds(mixed $value,array $known):array { if(!is_array($value)||!array_is_list($value)||!$value||count($value)>1000)self::bad();$seen=[];foreach($value as $id){self::positive($id);if(!isset($known[$id])||isset($seen[$id]))self::bad();$seen[$id]=true;}return $value; }
    private static function shop(array $row,string $shop):void { if(array_key_exists('shop_id',$row)&&((!is_string($row['shop_id'])&&!is_int($row['shop_id']))||(string)$row['shop_id']!==$shop))self::bad(); }
    private static function bounded(array $data):void { try {$json=json_encode($data,JSON_THROW_ON_ERROR);}catch(\JsonException){self::bad();}if(strlen($json)>262144)self::bad(); }
    private static function identity(mixed $value):void { if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$value))self::bad(); }
    private static function positive(mixed $value):void { if(!is_int($value)||$value<1||$value>2147483647)self::bad(); }
    private static function url(mixed $value):void { if(!is_string($value)||strlen($value)>2048||preg_match('/[\x00-\x20\x7f]/',$value)||filter_var($value,FILTER_VALIDATE_URL)===false)self::bad();$p=parse_url($value);if(($p['scheme']??null)!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['port'])||filter_var($p['host']??'',FILTER_VALIDATE_IP)!==false||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di',$p['host']??'')||preg_match('/(?:^|\.)(?:localhost|local|internal|lan|home)$/Di',$p['host']??''))self::bad(); }
    private static function bad():never { throw new \InvalidArgumentException('Product evidence does not match bounded read scope.'); }
    private static function rows(array $rows, int $max): void
    {
        if (!array_is_list($rows) || count($rows) > $max) { throw new \InvalidArgumentException('Unbounded provider list.'); }
    }
}
