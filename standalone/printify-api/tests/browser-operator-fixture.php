<?php
if (PHP_SAPI !== 'cli-server' || wp_get_environment_type() !== 'local'
    || DB_NAME !== 'printify_fixture' || !WP_HTTP_BLOCK_EXTERNAL) return;
define('DIGIFORGE_PRINTIFY_DIRECT_GET_FIXTURE',true);
define('DIGIFORGE_PRINTIFY_OFFLINE_SHOP','107');
define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT','fixture-account');
define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY',true);
define('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE',true);
add_filter('user_has_cap', static function (array $caps, array $requested, array $args, WP_User $user): array {
    if ($user->ID === 1) $caps['digiforge_printify_review_shop_108'] = true;
    return $caps;
},10,4);
define('DIGIFORGE_PRINTIFY_DIRECT_READ_SCOPE',['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>'custody-ref:fixture']);
define('DIGIFORGE_PRINTIFY_DIRECT_FIXTURE_PAT','fixture-test-only');
add_filter('pre_http_request',static fn()=>new WP_Error('fixture_only'),PHP_INT_MAX);
add_filter('pre_wp_mail',static fn()=>true);
add_filter('digiforge_printify_direct_fixture_gates',static fn()=>['stop_all'=>false,'external_lock'=>false]);
add_filter('digiforge_printify_direct_fixture_response',static function ($prior,array $request): array {
    $plugin=WP_PLUGIN_DIR.'/digiforge-printify-api';
    require_once $plugin.'/SyntheticPrintifyTransport.php';
    $fixture=new DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
    $variant=['id'=>9,'title'=>'Fixture source variant','options'=>['color'=>'Heather Grey','size'=>'XS','finish'=>'Matte'],
        'placeholders'=>[['position'=>'front','decoration_method'=>'dtf','width'=>1200,'height'=>900]]];
    $body=match ($request['operation']) {
        'VARIANTS_READ','VARIANT_READ'=>['id'=>42,'variants'=>[$variant]],
        'BLUEPRINT_SIZE_GUIDE_READ'=>['sizes'=>['XS'],'types'=>[['name'=>'Width','units'=>'cm','ranges'=>[['from'=>'40','to'=>'41']]]]],
        'BLUEPRINT_PROVIDER_READ'=>['id'=>7,'title'=>'Fixture provider','decoration_methods'=>['dtf'],'production_time'=>null],
        'PRODUCTS_READ' => ['current_page'=>1,'last_page'=>1,'per_page'=>10,'data'=>[[
            'id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,
            'variants'=>[['id'=>9,'sku'=>'fixture-sku','price'=>1000,'is_enabled'=>true]],'print_areas'=>[],
        ]]],
        'PRODUCT_GPSR_READ' => [['title'=>'Fixture safety instructions','text'=>'Fixture product safety information']],
        'ORDERS_READ' => ['current_page'=>1,'last_page'=>1,'per_page'=>10,'data'=>[[
            'id'=>'order-1','shop_id'=>107,'status'=>'partially-fulfilled',
            'line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
        ]]],
        'SUPPORT_LIST' => ['data'=>array_map(static fn(int $i): array => df_browser_support("support-$i"),range(1,11))],
        'SUPPORT_DETAIL' => ['data'=>df_browser_support('support-11')],
        'ORDER_READ'=>['id'=>'order-1','shop_id'=>107,'status'=>'partially-fulfilled',
            'line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
            'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/private']]],
        'PRODUCT_READ','PRODUCT_SKU_READ','PRODUCT_ARTWORK_READ'=>['id'=>'product-1','shop_id'=>107,'blueprint_id'=>42,'print_provider_id'=>7,
            'variants'=>[['id'=>9,'sku'=>'19779789605456636343','price'=>1000,'is_enabled'=>true]],
            'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[
                ['id'=>'artwork-1','name'=>'Fixture front.png','type'=>'image/png','width'=>1200,'height'=>900,
                    'x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0,'src'=>'https://private.example.invalid/signed'],
                ['id'=>'artwork-2','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0],
            ]]]]],
            'images'=>[['src'=>'https://images.printify.com/mockup/fixture.jpg','variant_ids'=>[9],'position'=>'front']]],
        default=>$fixture->request(['method'=>'GET','provider_shop_id'=>'107','operation'=>$request['operation'],
            'endpoint'=>$request['path'],'body'=>[]])['body'],
    };
    return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],'body'=>json_encode($body,JSON_THROW_ON_ERROR)];
},10,2);

function df_browser_support(string $id): array {
    return ['id'=>$id,'order_id'=>'order-1','type'=>'order_refund','status'=>'resolved',
        'outcome'=>'positive','line_item_ids'=>['line-1'],'created_at'=>'2026-11-19T00:00:00Z',
        'updated_at'=>'2026-11-20T00:00:00Z','description'=>'<script>fixture-private-description</script>'];
}
