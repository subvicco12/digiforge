<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Runtime;

check(method_exists(Runtime::class, 'inspectNativeHeldOperation'), 'Native held-operation adapter missing');
$missing = Runtime::inspectNativeHeldOperation('107', 'fixture-account', null, [], []);
check($missing['state'] === 'BLOCKED' && $missing['reason'] === 'NATIVE_CORE_UNAVAILABLE'
    && !$missing['retry_permitted'] && !$missing['provider_execution_authorized'], 'Missing Core granted authority');
$foreign = Runtime::inspectNativeHeldOperation('108', 'fixture-account', null, [], []);
check($foreign['state'] === 'DENIED' && $foreign['reason'] === 'OPERATOR_SCOPE', 'Foreign shop reached Core');
