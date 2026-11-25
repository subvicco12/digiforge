<?php
if (PHP_SAPI !== 'cli-server' || wp_get_environment_type() !== 'local' || DB_NAME !== 'printify_fixture'
    || !defined('WP_HTTP_BLOCK_EXTERNAL') || WP_HTTP_BLOCK_EXTERNAL !== true) {
    return;
}
define('DIGIFORGE_PRINTIFY_DIRECT_CONFIG_FILE',dirname(ABSPATH).'/operational-fixture.json');
add_filter('pre_http_request',static function ($prior,array $args,string $url) {
    if ($url !== 'https://api.printify.com/v1/shops/107/orders/order-1.json'
        || ($args['method'] ?? null)!=='GET'
        || !in_array($args['headers']['Authorization'] ?? null,
            ['Bearer synthetic-operational-not-a-pat','Bearer synthetic-rotated-not-a-pat'],true)) {
        return new WP_Error('offline_fixture');
    }
    $order = ['id'=>'order-1','shop_id'=>107,'external_id'=>'fixture-order',
        'status'=>'partially-fulfilled','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
        'shipments'=>[['carrier'=>'FixtureCarrier','number'=>'fixture-tracking-1','url'=>'https://example.invalid/receipt']]];
    return ['response'=>['code'=>200],'headers'=>['content-type'=>'application/json'],
        'body'=>json_encode($order,JSON_THROW_ON_ERROR)];
},PHP_INT_MAX,3);
add_filter('pre_wp_mail',static fn()=>true);
