<?php
if (PHP_SAPI !== 'cli-server' || wp_get_environment_type() !== 'local' || DB_NAME !== 'printify_fixture'
    || !defined('WP_HTTP_BLOCK_EXTERNAL') || WP_HTTP_BLOCK_EXTERNAL !== true) {
    return;
}
define('DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE',dirname(ABSPATH).'/operational-fixture.json');
add_filter('pre_http_request',static function ($prior,array $args,string $url) {
    if (($args['method'] ?? null)!=='GET'
        || !in_array($args['headers']['Authorization'] ?? null,
            ['Bearer synthetic-operational-not-a-pat','Bearer synthetic-rotated-not-a-pat'],true)) {
        return new WP_Error('offline_fixture');
    }
    if ($url === 'https://api.printify.com/v1/shops/107/orders/order-error.json') {
        return ['response'=>['code'=>429],'headers'=>['retry-after'=>'7'],'body'=>'{}'];
    }
    if ($url === 'https://api.printify.com/v1/shops/107/products/product-1.json') {
        $product = ['id'=>'product-1','shop_id'=>107,'title'=>'Coexistence fixture','blueprint_id'=>42,
            'print_provider_id'=>7,'variants'=>[
                ['id'=>9,'sku'=>'19779789605456636343','price'=>1000,'is_enabled'=>true],
                ['id'=>10,'sku'=>'other-sku','price'=>900,'is_enabled'=>true]],
            'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front','images'=>[
                ['id'=>'asset-1','name'=>'Fixture front.png','type'=>'image/png','width'=>1200,'height'=>900,
                    'x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0,'src'=>'https://private.example.invalid/secret'],
            ]]]]]];
        return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
            'body'=>json_encode($product,JSON_THROW_ON_ERROR)];
    }
    if ($url === 'https://api.printify.com/v2/catalog/blueprints/42/print_providers/7/shipping/standard.json') {
        require_once WP_PLUGIN_DIR.'/digiforge-printify-api/SyntheticPrintifyTransport.php';
        $transport = new \DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
        $reply = $transport->request(['method'=>'GET','operation'=>'CATALOG_SHIPPING_METHOD_READ',
            'endpoint'=>'/v2/catalog/blueprints/42/print_providers/7/shipping/standard.json','body'=>[],'provider_shop_id'=>'107']);
        return ['response'=>['code'=>$reply['status']],'headers'=>['content-type'=>'application/json'],
            'body'=>json_encode($reply['body'],JSON_THROW_ON_ERROR)];
    }
    if ($url !== 'https://api.printify.com/v1/shops/107/orders/order-1.json') {
        return new WP_Error('offline_fixture');
    }
    $order = ['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture-order',
        'status'=>'partially-fulfilled','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
        'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/receipt']]];
    return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
        'body'=>json_encode($order,JSON_THROW_ON_ERROR)];
},PHP_INT_MAX,3);
add_filter('pre_wp_mail',static fn()=>true);
