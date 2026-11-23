<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

check(method_exists(Runtime::class, 'inspectNativeHeldOperation'), 'Native held-operation adapter missing');
$missing = Runtime::inspectNativeHeldOperation('107', 'fixture-account', null, [], []);
check($missing['state'] === 'BLOCKED' && $missing['reason'] === 'NATIVE_CORE_UNAVAILABLE'
    && !$missing['retry_permitted'] && !$missing['provider_execution_authorized'], 'Missing Core granted authority');
$foreign = Runtime::inspectNativeHeldOperation('108', 'fixture-account', null, [], []);
check($foreign['state'] === 'DENIED' && $foreign['reason'] === 'OPERATOR_SCOPE', 'Foreign shop reached Core');

foreach (['AUTHORIZE', 'REGISTER', 'CLAIM', 'RECEIPT_READ'] as $action) {
    foreach ([
        [],
        ['provider_shop_id' => '108', 'environment' => 'test'],
        ['provider_shop_id' => 107, 'environment' => 'test'],
        ['provider_shop_id' => '107', 'environment' => 'sandbox'],
        ['provider_shop_id' => '107', 'environment' => 'production'],
    ] as $binding) {
        $result = Runtime::prepareNativeMutation('107', 'fixture-account', [
            'action' => $action, 'binding' => $binding,
        ]);
        check($result['state'] === 'DENIED' && $result['reason'] === 'NATIVE_BINDING'
            && !isset($result['canonical_request_shape_verified'])
            && !$result['provider_execution_authorized'] && !$result['retry_permitted'],
            'Native handoff accepted a missing or substituted fixture binding: ' . $action);
    }
}
