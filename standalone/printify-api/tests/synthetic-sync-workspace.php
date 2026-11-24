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
    $send = static function (string $operation, array $fields, bool $preview = false, array $approvedCreate = []) use ($workspace): string {
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
            if ($approvedCreate !== []) {
                $_POST['df_workspace_correlated_create'] = '1';
                $_POST['df_edit_proposal_id'] = $approvedCreate['id'];
                $_POST['df_edit_proposal_revision'] = (string)$approvedCreate['revision'];
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
    $operationResult = static function (string $html): array {
        requireFixture(preg_match('/<pre[^>]+aria-labelledby="df-operation-result"[^>]*>(.*?)<\/pre>/s', $html, $m) === 1,
            'Operation output missing');
        return json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true, 24, JSON_THROW_ON_ERROR);
    };
    $quote = ['external_id'=>'cp4-quote','product_id'=>'product-1','variant_id'=>'9','quantity'=>'1'];
    foreach (['1','2','3','4'] as $method) {
        $html = $send('ORDER_SHIPPING_QUOTE', $quote + ['destination'=>'us','shipping_method'=>$method], true);
        $result = $operationResult($html);
        requireFixture($result['state']==='SYNTHETIC_OBSERVED' && isset($result['data']['rates_minor']), 'US fixture method denied: '.$method);
    }
    foreach ([
        ['ca','4','SYNTHETIC_OBSERVED'], ['ca','2','FIXTURE_SHIPPING_METHOD_UNAVAILABLE'],
        ['gb_unavailable','1','FIXTURE_SHIPPING_UNAVAILABLE'], ['invalid_address','1','REQUEST_VALIDATION'],
    ] as [$destination,$method,$expected]) {
        $html = $send('ORDER_SHIPPING_QUOTE', $quote + ['destination'=>$destination,'shipping_method'=>$method], true);
        $result = $operationResult($html);
        requireFixture(($expected === 'SYNTHETIC_OBSERVED' ? $result['state'] : $result['reason']) === $expected,
            'Incorrect quote state for '.$destination.' '.$method);
    }
    $draft = $send('ORDER_CREATE', ['external_id'=>'cp4-ca-order'] + $quote + ['destination'=>'ca','shipping_method'=>'1'], true);
    requireFixture(str_contains($draft, 'PENDING_APPROVAL') && $before === $workspace->readRecords('orders', null, 1, 10)['data'],
        'Canada fixture preview committed an order or failed readiness');
    $art = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,2,0,0,0))
        .$chunk('IDAT',gzcompress("\0\xff\0\0")).$chunk('IEND','');
    $image = $submit('IMAGE_UPLOAD', ['file_name'=>'preview-four.png','contents'=>base64_encode($art)], true);
    requireFixture($image['proposal']['state']==='PENDING_APPROVAL' &&
        $image['transport_receipt']['requests'] === [['method'=>'POST','endpoint'=>'/v1/uploads/images.json','operation'=>'IMAGE_UPLOAD']]
        && !$image['provider_execution_authorized'] && !str_contains(json_encode($workspace->inspect(), JSON_THROW_ON_ERROR), base64_encode($art)),
        'Image preparation did not remain held or retained its bytes: '.($image['reason'] ?? 'none'));
    $revision = $workspace->inspect()['revision'];
    $unknown = $workspace->execute($revision, hash('sha256','checkpoint-4-lost-ack'), 'ORDER_CANCEL',
        ['order_id'=>'order-2'], true, 'lost_ack');
    requireFixture($unknown['proposal']['state']==='UNKNOWN' && !$unknown['retry_permitted']
        && $before === $workspace->readRecords('orders', null, 1, 10)['data'], 'Ambiguous preview escaped quarantine');
    $referred = $review($unknown['proposal'], 'refer_unknown');
    requireFixture($referred['state']==='UNKNOWN' && $referred['proposal']['referral']==='MANUAL_RECONCILIATION_REQUIRED'
        && !$referred['retry_permitted'] && $review($referred['proposal'], 'refer_unknown')['reason']==='PROPOSAL_TRANSITION_DENIED'
        && $review($referred['proposal'], 'approve')['reason']==='PROPOSAL_TRANSITION_DENIED', 'UNKNOWN referral compromised quarantine');

    $observe = static function (string $kind, string $resource) use ($submit,$workspace): string {
        $result = $submit('FIXTURE_READ_SYNC', ['kind'=>$kind,'integration'=>'fixture-read','selection'=>['id'=>$resource]]);
        $audit = $workspace->inspect()['audit'];
        requireFixture($result['state']==='OBSERVED' && $result['transport_receipt']['requests'][0]['method']==='GET',
            'Detail receipt not admitted');
        return end($audit)['operation_id'];
    };
    $observe('products','product-1');
    $editPayload=['product_id'=>'product-1','expected_version'=>1,'title'=>'Reconciled fixture version'];
    $productHeld = $review($submit('PRODUCT_UPDATE',$editPayload,true)['proposal'], 'approve')['proposal'];
    requireFixture($submit('PRODUCT_UPDATE',$editPayload)['state']==='SYNTHETIC_OBSERVED', 'Independent fixture product update failed');
    $productReceipt = $observe('products','product-1');
    $newProduct = $review($productHeld,'reconcile',['receipt_id'=>$productReceipt]);
    requireFixture(($newProduct['proposal']['observation_comparison']['changes']['synthetic_version'] ?? null)
        === ['before'=>1,'after'=>2] && !$newProduct['provider_outcome_confirmed'], 'Product observation difference incorrect');

    $observe('orders','order-2');
    $orderHeld = $review($submit('FIXTURE_PRODUCTION_CONFIRM',['order_id'=>'order-2'],true)['proposal'],'approve')['proposal'];
    requireFixture($submit('FIXTURE_PRODUCTION_CONFIRM',['order_id'=>'order-2'])['state']==='SYNTHETIC_OBSERVED'
        && $submit('FIXTURE_FULFILL',['order_id'=>'order-2'])['state']==='SYNTHETIC_OBSERVED', 'Fixture fulfillment failed');
    $html = $sync('orders',['id'=>'order-2']);
    requireFixture(str_contains($html, '<select name="df_proposal[receipt_id]"') && str_contains($html, 'Choose an observation'),
        'Scoped receipt selection unavailable');
    $recent = $workspace->inspect()['audit'];
    $shipment = $review($orderHeld,'reconcile',['receipt_id'=>end($recent)['operation_id']]);
    $changes = $shipment['proposal']['observation_comparison']['changes'] ?? [];
    requireFixture(($changes['shipments_count'] ?? null)===['before'=>0,'after'=>1]
        && ($changes['status'] ?? null)===['before'=>'on-hold','after'=>'fulfilled'] && !$shipment['provider_outcome_confirmed'],
        'Shipment receipt difference incorrect');
    $create = static function (string $op, array $input, array $proposal, ?string $fault = null) use ($workspace): array {
        $rev = $workspace->inspect()['revision'];
        return $workspace->execute($rev, hash('sha256','held-create:'.$rev), $op, $input, false, $fault,
            ['id'=>$proposal['id'],'revision'=>$proposal['revision']], true);
    };
    foreach (['PRODUCT_CREATE'=>['title'=>'Correlated fixture product']+$product,
        'ORDER_CREATE'=>['external_id'=>'correlated-cp5-order']+$order] as $op=>$payload) {
        $pending = $submit($op,$payload,true)['proposal'];
        requireFixture($pending['resource_id'] === null && $create($op,$payload,$pending)['reason']
            === 'PROPOSAL_SCOPE_OR_STALE_REVISION', 'Held approval was bypassed');
        $approved = $review($pending,'approve')['proposal'];
        $changed = $op === 'PRODUCT_CREATE' ? ['title'=>'Wrong revised product']+$payload : ['external_id'=>'wrong-order']+$payload;
        requireFixture($create($op,$changed,$approved)['reason']==='FIXTURE_CREATE_INTENT_MISMATCH', 'Substituted creation intent admitted');
        requireFixture($review($approved,'reconcile_create')['reason']==='PROPOSAL_TRANSITION_DENIED', 'Creation reconciled without a receipt');
        $made = $create($op,$payload,$approved);
        $creation = $made['proposal']['creation_receipt'] ?? [];
        requireFixture($made['state']==='FIXTURE_CREATE_RECORDED' && $made['fixture_effect_committed']
            && !$made['provider_execution_authorized'] && !$made['provider_outcome_confirmed']
            && $creation['approved_revision']===$approved['revision'] && $creation['proposal_id']===$approved['id']
            && $creation['scope']===$approved['scope'] && isset($creation['correlation_id'],$creation['projection_sha256']),
            'Exact fixture creation receipt not recorded: '.$op.' '.$made['reason']);
        requireFixture($create($op,$payload,$approved)['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION', 'Duplicate creation admitted');
        $linked = $review($made['proposal'],'reconcile_create');
        requireFixture($linked['state']==='RECONCILED_FIXTURE_CREATION' && !$linked['provider_outcome_confirmed']
            && $linked['proposal']['resource_id']===$creation['resource_id'], 'Verified fixture creation did not reconcile');
        requireFixture($review($made['proposal'],'reconcile_create')['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION',
            'Duplicate creation reconciliation admitted');
    }
    $fields = ['external_id'=>'operator-correlated-order'] + $formOrder;
    $operatorDraft = $operationResult($send('ORDER_CREATE',$fields,true))['proposal'];
    $operatorApproved = $review($operatorDraft,'approve')['proposal'];
    $originalCap = wp_get_current_user();
    $revokedRevision = $workspace->inspect()['revision'];
    $originalCap->remove_cap('digiforge_printify_review_shop_107');
    $revoked = $workspace->execute($revokedRevision, hash('sha256','revoked-correlated-create'), 'ORDER_CREATE',
        ['external_id'=>'other-scope']+$order, false, null,
        ['id'=>$operatorApproved['id'],'revision'=>$operatorApproved['revision']], true);
    $originalCap->add_cap('digiforge_printify_review_shop_107');
    requireFixture($revoked['state'] !== 'FIXTURE_CREATE_RECORDED' && $workspace->inspect()['revision']===$revokedRevision,
        'Unassigned shop created a correlated fixture');
    $operatorCreated = $operationResult($send('ORDER_CREATE',$fields,false,$operatorApproved));
    requireFixture($operatorCreated['state']==='FIXTURE_CREATE_RECORDED' &&
        $review($operatorCreated['proposal'],'reconcile_create')['state']==='RECONCILED_FIXTURE_CREATION',
        'Protected operator form did not materialize its approved draft');
    $transport = new \DigiForgePrintifyApi\SyntheticPrintifyTransport('107');
    $client = new \DigiForgePrintifyApi\SyntheticPrintifyClient('107',$transport);
    $client->run('IMAGE_UPLOAD',['file_name'=>'ambiguous.png','contents'=>base64_encode($png)]);
    $beforeTwo = $transport->exportFixtureState();
    $first = $client->run('PRODUCT_CREATE',['title'=>'Ambiguous fixture A']+$product);
    $second = $client->run('PRODUCT_CREATE',['title'=>'Ambiguous fixture B']+$product);
    $verifyCreation = new ReflectionMethod($workspace,'creationEvidence');
    requireFixture($first['state']==='SYNTHETIC_OBSERVED' && $second['state']==='SYNTHETIC_OBSERVED'
        && $verifyCreation->invoke($workspace,$transport,$beforeTwo,$first,'PRODUCT_CREATE',0)===null,
        'Ambiguous resource set accepted');
    $lostIntent=['external_id'=>'checkpoint-five-lost-create']+$order;
    $unknownDraft = $review($submit('ORDER_CREATE',$lostIntent,true)['proposal'],'approve')['proposal'];
    $recordsBeforeLoss = $workspace->readRecords('orders', null, 1, 10)['data'];
    $lost = $create('ORDER_CREATE',$lostIntent,$unknownDraft,'lost_ack');
    requireFixture($lost['state']==='UNKNOWN' && !$lost['fixture_effect_committed'] && !$lost['retry_permitted']
        && !isset($lost['proposal']['creation_receipt'])
        && $recordsBeforeLoss===$workspace->readRecords('orders',null,1,10)['data'], 'Ambiguous create committed state or could retry');
    requireFixture($create('ORDER_CREATE',$lostIntent,$lost['proposal'])['reason']==='PROPOSAL_SCOPE_OR_STALE_REVISION',
        'UNKNOWN create was retried');
    requireFixture($review($lost['proposal'],'refer_unknown')['state']==='UNKNOWN', 'UNKNOWN create referral failed');

    $custodyProfile=['custody'=>'custody-ref:fixture-cp5']+$profile;
    $nonce=wp_create_nonce('digiforge_printify_account_profile_save');
    requireFixture(\DigiForgePrintifyApi\AccountProfile::save($custodyProfile,$nonce)==='SAVED_LOCAL_METADATA', 'Custody profile save failed');
    $key=sodium_crypto_sign_keypair();
    $scope=['shop'=>'107','account_reference'=>'fixture-account','source_sha'=>str_repeat('a',40),
        'source_revision'=>1,'authorization_revision'=>1,'signer_key'=>base64_encode(sodium_crypto_sign_publickey($key))];
    $calls=0;
    $exchange=static function(array $request, int $deadline, int $maximum) use ($key,&$calls): array {
        ++$calls;
        requireFixture($deadline===2000 && $maximum===262144 && $request['method']==='GET'
            && $request['endpoint']==='/v1/shops/107/orders/order-1.json' && $request['credential_reference']==='fixture-cp5'
            && $request['integration_reference']==='fixture-read', 'Custody route or alias mismatch');
        $body='{"id":"order-1","shop_id":107,"status":"fulfilled","line_items":[]}';
        $record=json_encode(['schema'=>'DF_PRINTIFY_CUSTODY_READ_RECEIPT_1','evidence_class'=>'SIGNED_CUSTODY_FIXTURE',
            'request_sha256'=>hash('sha256',json_encode($request, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
            'content_sha256'=>hash('sha256',$body),'observed_at'=>time(),'expires_at'=>time()+30,'status'=>200,'retry_after'=>0],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        return ['record'=>$record,'signature'=>base64_encode(sodium_crypto_sign_detached(
            "DF_PRINTIFY_CUSTODY_READ_RECEIPT_1\n".$record,sodium_crypto_sign_secretkey($key))),'body'=>$body];
    };
    $read=Runtime::readFromConfiguredCustodyFixture('fixture-read',$scope,'ORDER_READ',['order_id'=>'order-1'],$exchange);
    requireFixture($read['state']==='AUTHENTICATED_CUSTODY_FIXTURE' && !$read['provider_execution_authorized'] && $calls===1,
        'Configured signed custody fixture was not verified');
    requireFixture(Runtime::readFromConfiguredCustodyFixture('different-integration',$scope,'ORDER_READ',
        ['order_id'=>'order-1'],$exchange)['state']==='DENIED' && $calls===1, 'Wrong integration reached custody');
    requireFixture(Runtime::readFromConfiguredCustodyFixture('fixture-read',$scope+['credential_reference'=>'override'],
        'ORDER_READ',['order_id'=>'order-1'],$exchange)['state']==='DENIED' && $calls===1, 'Credential override reached custody');
    requireFixture(Runtime::readFromConfiguredCustodyFixture('fixture-read',$scope,'PRODUCT_PUBLISH',[],$exchange)['state']==='DENIED'
        && $calls===1, 'Mutation reached fixture custody');
    $auditId = hash('sha256','durable-custody-fixture');
    $audit = static fn(int $revision, string $id, \Closure $call): array =>
        Runtime::auditConfiguredCustodyFixture($revision,$id,'fixture-read',$scope,'ORDER_READ',['order_id'=>'order-1'],$call);
    $revision = $workspace->inspect()['revision'];
    $recorded = $audit($revision,$auditId,$exchange);
    requireFixture($recorded['state']==='AUTHENTICATED_CUSTODY_FIXTURE' && $calls===2
        && isset($recorded['custody_receipt']['receipt_sha256']) && !$recorded['provider_execution_authorized'],
        'Signed read was not durably journaled');
    requireFixture($audit($revision,$auditId,$exchange)['reason']==='OPERATION_ALREADY_RECORDED' && $calls===2,
        'Durable custody receipt replay reached exchange');
    $lastRevision = $workspace->inspect()['revision'];
    $changed = $audit($lastRevision,hash('sha256','scope-change-custody'),static function (array $r,int $t,int $m) use ($exchange,$profile,$nonce): array {
        $receipt = $exchange($r,$t,$m);
        \DigiForgePrintifyApi\AccountProfile::save($profile,$nonce);
        return $receipt;
    });
    requireFixture($changed['state']==='DENIED' && $workspace->inspect()['revision']===$lastRevision && $calls===3,
        'Changed custody alias admitted an obsolete receipt');
    requireFixture(\DigiForgePrintifyApi\AccountProfile::save($custodyProfile,$nonce)==='SAVED_LOCAL_METADATA', 'Custody alias restore failed');
    $redacted=$workspace->redactedAuditExport();
    $encoded=json_encode($redacted, JSON_THROW_ON_ERROR);
    requireFixture($redacted['schema']==='DF_PRINTIFY_REDACTED_AUDIT_1'
        && str_contains($encoded,$recorded['custody_receipt']['receipt_sha256'])
        && $redacted['profile']['custody']==='FIXTURE_REFERENCE_CONFIGURED'
        && $redacted['profile']['change_receipts'][0]['alias_changed']
        && !str_contains($encoded,'fixture-cp5') && !str_contains($encoded,'workspace@example.invalid')
        && !str_contains($encoded,'fixture-account') && !str_contains($encoded,'signer_key'),
        'Redacted audit is incomplete or contains sensitive fields');
    $getExport = static function(string $nonce): string {
        $prior=$_POST;
        $_POST=['df_workspace_shop'=>'107','df_printify_audit_export'=>'1','df_printify_audit_nonce'=>$nonce];
        ob_start();
        try {
            Runtime::panel();
            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
            $_POST=$prior;
        }
    };
    requireFixture(!str_contains($getExport('invalid'), 'data:application/json;base64,'), 'Invalid export nonce admitted');
    $html=$getExport(wp_create_nonce('digiforge_printify_redacted_fixture_audit:107'));
    requireFixture(preg_match('/data:application\/json;base64,([A-Za-z0-9+\/=]+)/', $html, $match)===1
        && json_decode(base64_decode($match[1],true),true,24,JSON_THROW_ON_ERROR)===$redacted,
        'Protected export differs from its redacted projection');
    wp_set_current_user(0);
    requireFixture($workspace->redactedAuditExport()===null &&
        !str_contains($getExport('invalid'),'data:application/json;base64,'), 'Anonymous export admitted');
    wp_set_current_user(1);
    echo "Consolidated durable custody, alias-change denial and protected redacted export PASS\n";
    echo "Checkpoint 5 correlated product/order creation, UNKNOWN and signed profile custody PASS\n";
    echo "Checkpoint 4 quote variants, request receipts, UNKNOWN referral and field differences PASS\n";
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
    $history = $workspace->inspect()['proposals'];
    requireFixture(count(array_filter($history, static fn(array $p): bool => ($p['referral'] ?? null)
        === 'MANUAL_RECONCILIATION_REQUIRED' && $p['state'] === 'UNKNOWN')) === 2, 'UNKNOWN referral lost on reopen');
    requireFixture(count(array_filter($history, static fn(array $p): bool => isset($p['observation_comparison']['changes']['shipments_count']))) === 1,
        'Shipment difference lost on reopen');
    requireFixture(count(array_filter($history, static fn(array $p): bool =>
        $p['state']==='RECONCILED_FIXTURE_CREATION' && isset($p['creation_receipt']['projection_sha256'])))===3,
        'Correlated creation receipts lost on reopen');
    requireFixture(count(array_filter($history, static fn(array $p): bool =>
        $p['operation']==='ORDER_CREATE' && $p['state']==='UNKNOWN' && isset($p['referral'])))===1, 'Create UNKNOWN lost on reopen');
    $freshExport=$workspace->redactedAuditExport();
    $custodyAudit=array_values(array_filter($freshExport['entries'], static fn(array $row): bool =>
        $row['operation']==='FIXTURE_CUSTODY_READ' && isset($row['receipt_sha256'])));
    requireFixture(count($custodyAudit)===1 && $freshExport['profile']['change_receipts']!==[], 'Durable custody evidence lost on reopen');
    echo "Consolidated custody and profile receipts reopen PASS\n";
    echo "Checkpoint 5 correlated receipts and UNKNOWN reopen PASS\n";
    echo "Checkpoint 4 durable reconciliation and UNKNOWN reopen PASS\n";
    echo "Checkpoint 3 proposals reopen PASS\n";
    echo "Checkpoint 2 durable receipts and previews reopen PASS\n";
}
