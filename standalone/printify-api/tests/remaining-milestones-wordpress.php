<?php
declare(strict_types=1);

use DigiForgePrintifyApi\AccountProfile;
use DigiForgePrintifyApi\Runtime;

$active = ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>''];
$alternate = ['shop'=>'107','account'=>'fixture-account','integration'=>'alternate-fixture','custody'=>'custody-ref:synthetic-alias'];
$key = static fn(array $p): string => hash('sha256', implode("\n", [$p['shop'],$p['account'],$p['integration']]));
$expected = static fn(array $p): string => hash_hmac('sha256', json_encode($p, JSON_THROW_ON_ERROR), wp_salt('auth'));
$nonce = wp_create_nonce('digiforge_printify_account_profile_save');
if ($phase === 'reopen') {
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $plugin='digiforge-printify-api/digiforge-printify-api.php';
    $before=$workspace->inspect();
    deactivate_plugins($plugin);
    requireFixture(!is_plugin_active($plugin) && activate_plugin($plugin) === null
        && get_option('active_plugins') === [$plugin] && $workspace->inspect() === $before,
        'Standalone reactivation lost workspace evidence');
    requireFixture(AccountProfile::current() === $alternate && AccountProfile::diagnostics()['custody'] === 'FIXTURE_REFERENCE_CONFIGURED'
        && $workspace->inspect()['proposals'] === [], 'Active profile or scope isolation lost after reopen');
    requireFixture(AccountProfile::switchProfile($key($active), $expected($alternate), $nonce) === 'SAVED_LOCAL_METADATA',
        'Previously validated profile could not be restored');
    $proposals = $workspace->inspect()['proposals'];
    requireFixture(count(array_filter($proposals, static fn(array $p): bool => $p['state'] === 'UNKNOWN')) === 2
        && Runtime::integrationReadiness('107','fixture-account')['native_mutation']['core_build'] === 'NATIVE_CORE_UNAVAILABLE',
        'Reopen lost quarantine or missing dependency denial');
    echo "Reopen PASS: reactivation persistence, profile restore, two quarantines and held dependency denial\n";
    return;
}
$post = static function (string $op, array $fields, bool $preview = false) use ($send,$operationResult): array {
    return $operationResult($send($op,$fields,$preview));
};
$png2 = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0))
    .$chunk('IDAT',gzcompress("\0\0\0\xff")).$chunk('IEND','');
requireFixture($post('IMAGE_UPLOAD',['file_name'=>'archivable.png','contents'=>base64_encode($png2)])['data']['id'] === 'image-2',
    'Second fixture image missing');
$first = $post('UPLOADS_READ',['page'=>'1','limit'=>'1']);
requireFixture(count($first['data']['data']) === 1 && $first['data']['next_page'] === ['page'=>2,'limit'=>1]
    && !$first['data']['pagination_followed'], 'First page did not return a bounded continuation');
$next = $post('UPLOADS_READ',array_map('strval',$first['data']['next_page']));
requireFixture($next['data']['data'][0]['id'] !== $first['data']['data'][0]['id'] && $next['data']['next_page'] === null,
    'Second page duplicated identity or advertised a phantom continuation');
requireFixture($post('UPLOADS_READ',['page'=>'0','limit'=>'1'])['reason'] === 'INVALID_OPERATION_FIELD', 'Invalid continuation admitted');
requireFixture($post('IMAGE_ARCHIVE',['image_id'=>'image-1'])['reason'] === 'CONFLICT'
    && $post('IMAGE_ARCHIVE',['image_id'=>'image-2'])['data']['archived'] === true
    && $post('IMAGE_READ',['image_id'=>'image-2'])['reason'] === 'NOT_FOUND'
    && $post('IMAGE_ARCHIVE',['image_id'=>'image-2'])['reason'] === 'NOT_FOUND',
    'Archive lifecycle admitted in-use or unavailable artwork');
$result = $post('PRODUCT_UPDATE', ['product_id'=>'product-1','expected_version'=>'1','variant_id'=>'9','price'=>'1234',
    'placements'=>[['variant_id'=>'9','image_id'=>'image-2','position'=>'front']]]);
requireFixture($result['reason'] === 'VALIDATION', 'Archived image could be placed on a product');
$quote=['external_id'=>'quote-ninth','product_id'=>'product-1','variant_id'=>'9','quantity'=>'100'];
foreach (['us_east'=>['1','2','3','4'],'ca_west'=>['1','4']] as $destination=>$methods) {
    foreach ($methods as $method) {
        $result = $post('ORDER_SHIPPING_QUOTE',$quote+['destination'=>$destination,'shipping_method'=>$method],true);
        requireFixture($result['state']==='SYNTHETIC_OBSERVED' && !$result['data']['quote_is_guarantee'], 'Bounded synthetic quote unavailable');
    }
}
requireFixture($post('ORDER_SHIPPING_QUOTE',['variant_id'=>'10']+$quote+['destination'=>'ca_west','shipping_method'=>'1'],true)['state']==='UNAVAILABLE'
    && $post('ORDER_SHIPPING_QUOTE',['quantity'=>'101']+$quote+['destination'=>'us_east','shipping_method'=>'1'],true)['state']!=='SYNTHETIC_OBSERVED',
    'Unavailable variant or out-of-range quantity quoted');

$decide = static function (array $proposal, string $decision, ?string $fault = null) use ($workspace): array {
    $revision = $workspace->inspect()['revision'];
    return $workspace->execute($revision, hash('sha256','nine:'.$revision), 'FIXTURE_PROPOSAL_REVIEW',
        ['proposal_id'=>$proposal['id'],'proposal_revision'=>$proposal['revision'],'decision'=>$decision], readFault:$fault);
};
foreach (['PRODUCT_UNPUBLISH_NOTIFY'=>['product_id'=>'product-2','expected_version'=>'3'],'ORDER_CANCEL'=>['order_id'=>'order-2']] as $op=>$fields) {
    $draft = $post($op,$fields,true)['proposal'];
    $held = $decide($draft,'approve')['proposal'];
    $result = $decide($held,'simulate_held','lost_ack');
    requireFixture($result['state'] === 'UNKNOWN' && !$result['retry_permitted'] && !$result['fixture_effect_committed']
        && $decide($held,'simulate_held')['reason'] === 'FIXTURE_TRANSITION_APPROVAL_MISMATCH',
        'Ambiguous product or cancellation replay was admitted');
}
requireFixture($workspace->readRecords('products','product-2',1,10)['data']['publication_state'] === 'PUBLISHED'
    && $workspace->readRecords('orders','order-2',1,10)['data']['status'] === 'on-hold',
    'Unacknowledged fixture effect escaped quarantine');

requireFixture(AccountProfile::save($alternate,$nonce) === 'SAVED_LOCAL_METADATA'
    && AccountProfile::switchProfile($key($active), $expected($active), $nonce) === 'INVALID_OR_UNASSIGNED_SCOPE'
    && $workspace->redactedAuditExport()['entries'] === []
    && AccountProfile::plan('107','fixture-account','fixture-read','orders')['state'] === 'DENIED',
    'Stale profile or previous integration evidence crossed scope');
requireFixture(AccountProfile::switchProfile($key($active), $expected($alternate), $nonce) === 'SAVED_LOCAL_METADATA'
    && AccountProfile::switchProfile($key($alternate), $expected($active), $nonce) === 'SAVED_LOCAL_METADATA'
    && AccountProfile::plan('107','fixture-account','alternate-fixture','orders')['transport'] === 'DISABLED',
    'Saved local switch failed or enabled transport');
echo "Remaining fixture milestones PASS: scoped profile switching, bounded continuation, archive denial, eight quote cases, publication/cancellation UNKNOWN\n";
