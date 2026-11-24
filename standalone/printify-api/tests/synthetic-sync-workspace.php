<?php
declare(strict_types=1);

/** Run in a clean local WordPress database with the extracted Standalone package. */
use DigiForgePrintifyApi\Runtime;

$root = getenv('DF_PRINTIFY_WP_FIXTURE_ROOT');
$socket = getenv('DF_PRINTIFY_WP_FIXTURE_SOCKET');
$phase = $argv[1] ?? '';
if (!is_string($root) || !str_starts_with($root, '/tmp/df-printify-')
    || !is_string($socket) || !str_starts_with($socket, '/tmp/df-printify-')
    || !in_array($phase, ['write', 'reopen'], true)) {
    throw new RuntimeException('Disposable fixture paths required');
}
require $root.'/wp-load.php';
if (wp_get_environment_type() !== 'local' || !defined('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE')
    || DIGIFORGE_PRINTIFY_DISPOSABLE_STORE !== true || DB_HOST !== 'localhost:'.$socket || DB_NAME !== 'printify_fixture') {
    throw new RuntimeException('Disposable database required');
}
define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
wp_set_current_user(1);
add_filter('pre_http_request', static function (): never { throw new RuntimeException('Unexpected HTTP'); }, PHP_INT_MAX);
function requireFixture(bool $ok, string $error): void
{
    if (!$ok) {
        throw new RuntimeException($error);
    }
}
if ($phase === 'write') {
    $db = new PDO('mysql:unix_socket='.$socket.';dbname=printify_fixture', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE df_printify_fixture_workspace (
        scope_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        revision int unsigned NOT NULL, state_json mediumblob NOT NULL, seal char(64) NOT NULL
    ) ENGINE=InnoDB');
}
$workspace = Runtime::syntheticWorkspace('107');
requireFixture($workspace !== null, 'Configured disposable workspace unavailable');
if ($phase === 'write') {
    $run = static function (string $op, array $input) use ($workspace): void {
        $rev = $workspace->inspect()['revision'];
        $result = $workspace->execute($rev, hash('sha256','seed:'.$rev), $op, $input);
        requireFixture($result['state'] === 'SYNTHETIC_OBSERVED', $op.': '.($result['reason'] ?? 'UNKNOWN'));
    };
    $chunk = static fn(string $t,string $d):string=>pack('N',strlen($d)).$t.$d.hash('crc32b',$t.$d,true);
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0))
        .$chunk('IDAT',gzcompress("\0\0\xff\0")).$chunk('IEND','');
    $run('IMAGE_UPLOAD', ['file_name'=>'fixture.png','contents'=>base64_encode($png)]);
    $product = [
        'title'=>'Fixture mug','description'=>'Synthetic','blueprint_id'=>42,'print_provider_id'=>7,
        'variants'=>[['id'=>9,'price'=>1234,'is_enabled'=>true]],
        'print_areas'=>[['variant_ids'=>[9],'placeholders'=>[['position'=>'front',
            'images'=>[['id'=>'image-1','x'=>0.5,'y'=>0.5,'scale'=>1,'angle'=>0]]]]]],
    ];
    $run('PRODUCT_CREATE', $product);
    $run('PRODUCT_CREATE', ['title'=>'Second fixture mug']+$product);
    $run('PRODUCT_PUBLISH', ['product_id'=>'product-2','expected_version'=>1,'publish_fields'=>['title'=>true]]);
    $run('PRODUCT_PUBLISH_SUCCEEDED', ['product_id'=>'product-2','expected_version'=>2,
        'external'=>['id'=>'fixture-listing','handle'=>'https://products.example.invalid/fixture-listing']]);
    $order = ['external_id'=>'first-order','line_items'=>[['product_id'=>'product-1','variant_id'=>9,'quantity'=>1]],
        'shipping_method'=>1,'send_shipping_notification'=>false,'is_printify_express'=>false,'is_economy_shipping'=>false,
        'address_to'=>['first_name'=>'Synthetic','last_name'=>'Buyer','email'=>'fixture@example.invalid','phone'=>'5550100',
            'country'=>'US','region'=>'CA','address1'=>'100 Fixture Street','address2'=>'','city'=>'Testville','zip'=>'90210']];
    $run('ORDER_CREATE', $order);
    $run('ORDER_CREATE', ['external_id'=>'second-order']+$order);
    $run('FIXTURE_PRODUCTION_CONFIRM', ['order_id'=>'order-1']);
    $run('FIXTURE_FULFILL', ['order_id'=>'order-1']);
}
$formOrder=['external_id'=>'fixture-order','product_id'=>'product-1','variant_id'=>'9','quantity'=>'2','shipping_method'=>'1'];
if ($phase === 'write') {
    $profile = ['shop'=>'107','account'=>'fixture-account','integration'=>'fixture-read','custody'=>''];
    requireFixture(\DigiForgePrintifyApi\AccountProfile::save($profile,
        wp_create_nonce('digiforge_printify_account_profile_save')) === 'SAVED_LOCAL_METADATA', 'Fixture profile setup failed');
    $send = static function (string $operation, array $fields, bool $preview = false) use ($workspace): string {
        $revision = $workspace->inspect()['revision'];
        $prior = $_POST;
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['df_workspace_shop'=>'107','df_workspace_revision'=>(string)$revision,
            'df_workspace_id'=>hash('sha256','checkpoint-two:'.$revision),
            'df_workspace_nonce'=>wp_create_nonce('df_printify_synthetic_workspace_v1:107'),
            'df_workspace_operation'=>$operation];
        if ($operation === 'FIXTURE_READ_SYNC') {
            $_POST['df_workspace_sync'] = $fields;
        } else {
            $_POST['df_workspace_fields_present'] = '1';
            $_POST['df_workspace_fields'] = wp_slash($fields);
            if ($preview) {
                $_POST['df_workspace_preview'] = '1';
            }
        }
        ob_start();
        try {
            Runtime::panel();
            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
            $_POST = $prior;
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    };
    $sync = static fn(string $kind, array $select = []): string =>
        $send('FIXTURE_READ_SYNC', ['kind'=>$kind,'integration'=>'fixture-read']+$select);
    $catalog = $sync('catalog', ['blueprint_id'=>'42','provider_id'=>'7','variant_id'=>'9','method'=>'standard']);
    foreach (['projection_sha256','FIXTURE_ONLY','1200','500'] as $marker) {
        requireFixture(str_contains($catalog, $marker), 'Catalog synchronization marker missing: '.$marker);
    }
    requireFixture(str_contains($sync('products', ['page'=>'1','limit'=>'1']), 'next_page'), 'Product list paging absent');
    requireFixture(str_contains($sync('products', ['id'=>'product-1']), 'product-1'), 'Product detail sync absent');
    requireFixture(str_contains($sync('orders', ['limit'=>'1']), 'next_page'), 'Order list paging absent');
    requireFixture(str_contains($sync('orders', ['id'=>'order-1']), 'SYNTHETIC-order-1'), 'Order tracking sync absent');
    requireFixture(str_contains($sync('orders', ['page'=>'101']), 'INVALID_SELECTION'), 'Unbounded page admitted');
    requireFixture(str_contains($send('FIXTURE_READ_SYNC', ['kind'=>'orders','integration'=>'different']), 'SYNC_SCOPE_OR_TRANSPORT'),
        'Wrong integration scope admitted');
    $sourceInputs = ['kind'=>'orders','integration'=>'fixture-read','selection'=>[]];
    foreach (['rate_limit'=>'RATE_LIMITED','timeout'=>'UNAVAILABLE'] as $fault=>$state) {
        $revision = $workspace->inspect()['revision'];
        $id = hash('sha256','checkpoint-fault:'.$fault);
        $result = $workspace->execute($revision, $id, 'FIXTURE_READ_SYNC', $sourceInputs, false, $fault);
        requireFixture($result['state'] === $state && !$result['retry_permitted'], 'Fault did not fail closed');
        if ($fault === 'rate_limit') {
            requireFixture(is_int($result['retry_after']) && $result['retry_after'] > 0, 'Missing bounded retry hint');
        }
        requireFixture($workspace->execute($revision + 1, $id, 'FIXTURE_READ_SYNC', $sourceInputs)['reason']
            === 'OPERATION_ALREADY_RECORDED', 'Replay of receipt admitted');
    }
    $before = $workspace->readRecords('orders', null, 1, 10)['data'];
    $beforeProducts = $workspace->readRecords('products', null, 1, 10)['data'];
    $previewOrder = array_replace($formOrder, ['external_id'=>'checkpoint-preview-order']);
    foreach (['ORDER_CREATE','ORDER_SHIPPING_QUOTE'] as $operation) {
        $html = $send($operation, $previewOrder, true);
        requireFixture(str_contains($html, 'Preview only.') && str_contains($html, 'SYNTHETIC_OBSERVED')
            && str_contains($html, 'fixture_effect_committed'), 'Expected held order preview: '.$operation);
    }
    $html = $send('PRODUCT_CREATE', [
        'title'=>'Preview mug','description'=>'Synthetic','blueprint_id'=>'42','print_provider_id'=>'7',
        'variant_id'=>'9','price'=>'1234','image_id'=>'image-1','position'=>'front',
    ], true);
    requireFixture(str_contains($html, 'Preview only.') && str_contains($html, 'SYNTHETIC_OBSERVED'), 'Product draft preview failed');
    foreach ([
        'PRODUCT_UPDATE'=>['product_id'=>'product-1','expected_version'=>'1','title'=>'Preview only title'],
        'ORDER_CANCEL'=>['order_id'=>'order-2'],
        'FIXTURE_PRODUCTION_CONFIRM'=>['order_id'=>'order-2'],
    ] as $operation=>$fields) {
        $html = $send($operation, $fields, true);
        requireFixture(str_contains($html, 'Preview only.') && str_contains($html, 'SYNTHETIC_OBSERVED'),
            'Lifecycle form preview failed: '.$operation);
    }
    foreach ([
        'PRODUCT_PUBLISH'=>['product_id'=>'product-1','expected_version'=>1,'publish_fields'=>['title'=>true]],
        'PRODUCT_UNPUBLISH_NOTIFY'=>['product_id'=>'product-2','expected_version'=>3],
    ] as $operation=>$input) {
        $revision = $workspace->inspect()['revision'];
        $result = $workspace->execute($revision, hash('sha256','publication-preview:'.$revision), $operation, $input, true);
        requireFixture($result['state'] === 'SYNTHETIC_OBSERVED' && $result['preview_only']
            && !$result['fixture_effect_committed'] && !$result['provider_execution_authorized'], 'Publication preview failed: '.$operation);
    }
    requireFixture($before === $workspace->readRecords('orders', null, 1, 10)['data']
        && $beforeProducts === $workspace->readRecords('products', null, 1, 10)['data'], 'Preview changed fixture records');
    $submit = static function (string $op, array $input, bool $preview = false, array $edit = []) use ($workspace): array {
        $revision = $workspace->inspect()['revision'];
        return $workspace->execute($revision, hash('sha256','proposal:'.$revision), $op, $input, $preview, null, $edit);
    };
    $review = static fn(array $proposal, string $decision, array $extra = []): array => $submit('FIXTURE_PROPOSAL_REVIEW',
        ['proposal_id'=>$proposal['id'],'proposal_revision'=>$proposal['revision'],'decision'=>$decision] + $extra);
    requireFixture(str_contains($send('PRODUCT_PUBLISH', ['product_id'=>'product-1','expected_version'=>'1',
        'publication_field'=>'title'], true), 'PENDING_APPROVAL'), 'Structured publishing preview failed');
    requireFixture(str_contains($send('PRODUCT_UNPUBLISH_NOTIFY', ['product_id'=>'product-2','expected_version'=>'3'], true),
        'PENDING_APPROVAL'), 'Structured unpublishing preview failed');
    $approval = $submit('ORDER_CANCEL', ['order_id'=>'order-2'], true)['proposal'];
    requireFixture($approval['state']==='PENDING_APPROVAL' && $approval['revision']===1, 'Pending proposal not persisted');
    $edit = ['id'=>$approval['id'],'revision'=>1];
    $updated = $submit('ORDER_CANCEL', ['order_id'=>'order-2'], true, $edit)['proposal'];
    requireFixture($updated['id']===$approval['id'] && $updated['revision']===2, 'Revision did not advance');
    requireFixture($submit('ORDER_CANCEL', ['order_id'=>'order-2'], true, $edit)['reason']
        === 'PROPOSAL_SCOPE_OR_STALE_REVISION', 'Stale edit admitted');
    requireFixture($review($approval, 'approve')['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION', 'Stale approval admitted');
    $held = $review($updated, 'approve');
    requireFixture($held['state']==='APPROVED_HELD' && !$held['provider_execution_authorized'], 'Approval granted execution');
    requireFixture($review($updated, 'approve')['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION', 'Duplicate approval admitted');
    $wrong = $submit('FIXTURE_READ_SYNC', ['kind'=>'orders','integration'=>'fixture-read','selection'=>['id'=>'order-1']]);
    $receipts = array_values(array_filter($workspace->inspect()['audit'], static fn(array $a): bool => isset($a['sync_receipt'])));
    $wrongId = end($receipts)['operation_id'];
    requireFixture($wrong['state']==='OBSERVED' && $review($held['proposal'], 'reconcile', ['receipt_id'=>$wrongId])['reason']
        === 'PROPOSAL_RECEIPT_MISMATCH', 'Wrong-resource receipt admitted');
    $submit('FIXTURE_READ_SYNC', ['kind'=>'orders','integration'=>'fixture-read','selection'=>['id'=>'order-2']]);
    $receipts = array_values(array_filter($workspace->inspect()['audit'], static fn(array $a): bool => isset($a['sync_receipt'])));
    $receiptId = end($receipts)['operation_id'];
    $reconciled = $review($held['proposal'], 'reconcile', ['receipt_id'=>$receiptId]);
    requireFixture($reconciled['state']==='RECONCILED_FIXTURE_OBSERVATION' && !$reconciled['provider_outcome_confirmed'],
        'Matching receipt did not link as observation');
    $bad = $submit('ORDER_CANCEL', ['order_id'=>'order-99'], true)['proposal'];
    requireFixture($bad['state']==='BLOCKED' && $review($bad, 'reject')['state']==='REJECTED', 'Blocked proposal not rejectable');
    $cancel = $submit('ORDER_CANCEL', ['order_id'=>'order-2'], true)['proposal'];
    requireFixture($review($cancel, 'cancel')['state']==='CANCELLED', 'Cancellation decision not retained');
    $otherProfile = ['account'=>'different-fixture'] + $profile;
    requireFixture(\DigiForgePrintifyApi\AccountProfile::save($otherProfile,
        wp_create_nonce('digiforge_printify_account_profile_save')) === 'SAVED_LOCAL_METADATA', 'Other scope setup failed');
    requireFixture($workspace->inspect()['proposals'] === [] && $review($reconciled['proposal'], 'cancel')['reason']
        === 'PROPOSAL_SCOPE_OR_STALE_REVISION', 'Cross-account proposal disclosed or accepted');
    requireFixture(\DigiForgePrintifyApi\AccountProfile::save($profile,
        wp_create_nonce('digiforge_printify_account_profile_save')) === 'SAVED_LOCAL_METADATA', 'Original scope restore failed');
    requireFixture($submit('FIXTURE_PROPOSAL_REVIEW',
        ['proposal_id'=>$cancel['id'],'proposal_revision'=>['malformed'],'decision'=>'cancel'])['reason']
        === 'PROPOSAL_SCOPE_OR_STALE_REVISION', 'Malformed proposal revision admitted');
    wp_set_current_user(0);
    requireFixture($workspace->execute(0, hash('sha256','anonymous'), 'FIXTURE_PROPOSAL_REVIEW', [])['state']==='DENIED',
        'Anonymous review admitted');
    wp_set_current_user(1);
    requireFixture($before === $workspace->readRecords('orders', null, 1, 10)['data'], 'Review mutated order data');
    echo "Checkpoint 3 fixture proposal workflow PASS\n";
    echo "Checkpoint 2 synchronization and product/order previews PASS\n";
} else {
    $retained = array_column($workspace->inspect()['audit'], 'sync_receipt');
    requireFixture(count($retained) >= 7 && $retained[0]['freshness'] === 'FIXTURE_ONLY'
        && preg_match('/^[a-f0-9]{64}$/D', $retained[0]['projection_sha256']) === 1, 'Synchronization receipt lost on reopen');
    requireFixture(count(array_filter($workspace->inspect()['audit'], static fn(array $a): bool =>
        ($a['preview_only'] ?? false) === true)) >= 8, 'Preview audit lost on reopen');
    $states = array_column($workspace->inspect()['proposals'], 'state');
    foreach (['REJECTED','CANCELLED','RECONCILED_FIXTURE_OBSERVATION'] as $state) {
        requireFixture(in_array($state,$states,true), 'Proposal state lost on reopen: '.$state);
    }
    echo "Checkpoint 3 proposals reopen PASS\n";
    echo "Checkpoint 2 durable receipts and previews reopen PASS\n";
}
