<?php
declare(strict_types=1);

/** Exercise typed form inputs without a WordPress install or external connection. */
define('ABSPATH', __DIR__.'/fixture-wordpress/');
function wp_unslash(string $value): string { return stripslashes($value); }
require ($argv[1] ?? __DIR__.'/../digiforge-printify-api').'/SyntheticWorkspacePanel.php';

$method = new ReflectionMethod(DigiForgePrintifyApi\SyntheticWorkspacePanel::class, 'fieldInput');
$parse = static fn(string $operation, array $fields): array => $method->invoke(null, '107', $operation, $fields);
$checks = 0;
$expect = static function (bool $condition, string $scenario) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($scenario);
    }
    ++$checks;
};
$deny = static function (string $op, array $fields, string $key) use ($parse,$expect): void {
    try {
        $parse($op,$fields);
    } catch (InvalidArgumentException $e) {
        $expect($e->getMessage() === $key,'Unexpected validation result for '.$key);
        return;
    }
    throw new RuntimeException('Invalid field admitted: '.$key);
};
$product = ['product_id'=>'product-1','expected_version'=>'2'];
$publish = $parse('PRODUCT_PUBLISH', $product+['publication_fields'=>['shipping_template','title','keyFeatures']]);
$expect($publish['publish_fields'] === ['title'=>true,'keyFeatures'=>true,'shipping_template'=>true], 'Publishing flags lost or unordered');
$expect($parse('PRODUCT_PUBLISH', $product+['publication_field'=>'images'])['publish_fields'] === ['images'=>true], 'Previous form submission broke');
$deny('PRODUCT_PUBLISH',$product+['publication_fields'=>[]],'publication_fields');
$deny('PRODUCT_PUBLISH',$product+['publication_fields'=>['title','title']],'publication_fields');
$deny('PRODUCT_PUBLISH',$product+['publication_fields'=>['title','unknown']],'publication_fields');
$deny('PRODUCT_PUBLISH',$product+['publication_field'=>'title','publication_fields'=>['title']],'publication_fields');
$success = $parse('PRODUCT_PUBLISH_SUCCEEDED',$product+['publication_reference'=>'fixture-listing']);
$expect($success['external'] === ['id'=>'fixture-listing','handle'=>'https://products.example.invalid/fixture-listing'], 'Unexpected publication reference');
$deny('PRODUCT_PUBLISH_SUCCEEDED',$product+['publication_reference'=>'https://arbitrary.example/'],'publication_reference');
$expect($parse('PRODUCT_PUBLISH_FAILED',$product+['publication_reason'=>'CHANNEL_TIMEOUT'])['reason'] === 'CHANNEL_TIMEOUT','Fixture timeout reason rejected');
$deny('PRODUCT_PUBLISH_FAILED',$product+['publication_reason'=>'anything'],'publication_reason');
$variants = $product+['variant_id'=>'9','price'=>'1234','variants'=>[
    ['variant_id'=>'10','price'=>'0','variant_enabled'=>'disabled'],
], 'placements'=>[
    ['variant_id'=>'9','image_id'=>'image-1','position'=>'front'],
    ['variant_id'=>'10','image_id'=>'image-1','position'=>'back'],
]];
$update = $parse('PRODUCT_UPDATE',$variants);
$expect($update['variants'] === [
    ['id'=>9,'price'=>1234,'is_enabled'=>true],
    ['id'=>10,'price'=>0,'is_enabled'=>false],
], 'Variant flags or prices lost');
$expect($update['known_variant_ids'] === [9,10] && $update['print_areas'][1]['variant_ids'] === [10], 'Placement update lost variant binding');
$deny('PRODUCT_UPDATE',['price'=>'0']+$variants,'price');
$deny('PRODUCT_UPDATE',$product+['variant_enabled'=>'disabled'],'variant_id');
$deny('PRODUCT_UPDATE',['variants'=>[['variant_id'=>'9','price'=>'1234']]]+$variants,'variants');
foreach (['ORDER_ADDRESS_CHANGE','SUPPORT_ADDRESS_CHANGE'] as $operation) {
    $address = $parse($operation,['order_id'=>'order-1','destination'=>'ca_west']);
    $expect($address['order_id'] === 'order-1' && $address['country'] === 'CA' && $address['region'] === 'BC'
        && !isset($address['destination']) && $address['email'] === 'workspace@example.invalid','Synthetic destination misbound');
    $deny($operation,['order_id'=>'order-1','destination'=>'us','address1'=>'Unapproved address'],'OPERATION_FIELDS');
    $deny($operation,['order_id'=>'order-1','destination'=>'other'],'destination');
}
$expect($parse('SUPPORT_DETAIL',['order_id'=>'order-1','support_request_id'=>'support-1']) === [
    'order_id'=>'order-1','support_request_id'=>'support-1',
], 'Support detail proof did not bind exact IDs');

echo "Structured operator forms PASS ($checks checks)\n";
