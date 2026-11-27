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


$boundary = Runtime::inspectHeldAuthority('107', 'fixture-account', null, [], []);
check($boundary['schema'] === 'DF_STANDALONE_HELD_AUTHORITY_1'
    && $boundary['admission'] === 'DENIED'
    && $boundary['dispatch'] === 'DISABLED_BY_POLICY'
    && $boundary['handoff'] === null && $boundary['attempt_id'] === null
    && $boundary['acknowledgment'] === 'UNVERIFIED'
    && !$boundary['provider_execution_authorized'] && !$boundary['retry_permitted'],
    'Held runtime boundary admitted execution without Core or an external authority');

require_once dirname($plugin).'/HeldSignedEvidence.php';
$wire = rtrim(file_get_contents(__DIR__.'/authority/signed-positive-vector.json'), "\n");
$vector = json_decode($wire, true, 8, JSON_THROW_ON_ERROR);
$identity = array_diff_key($vector['manifest'], array_flip(['version', 'issuer', 'audience', 'phase', 'issued', 'expires']));
$key = hex2bin('2152f8d19b791d24453242e15f2eab6cb7cffa7b6a5ed30097960e069881db12');
$verify = static fn(string $bytes, array $expected, int $now, bool $revoked = false): bool =>
    DigiForgePrintifyApi\HeldSignedEvidence::matches(
        $bytes, 'fixture-v1', 'fixture-witness', 'fixture-sender', $key, $revoked, $now, $expected
    );
check($verify($wire, $identity, 1700000000) === function_exists('sodium_crypto_sign_verify_detached'),
    'Signed offline vector produced incorrect evidence result');
check(!$verify($wire, $identity, 1700000015), 'Expired signed vector accepted');
check(!$verify($wire, $identity, 1700000000, true), 'Revoked signer accepted');
check(!$verify(' '.$wire, $identity, 1700000000), 'Noncanonical encoding accepted');
check(!$verify(str_replace('"version":1', '"version":1,"version":1', $wire), $identity, 1700000000),
    'Duplicate JSON key accepted');
check(!$verify($wire, array_replace($identity, ['shop'=>'13']), 1700000000), 'Cross-shop envelope accepted');
check(!$verify($wire, array_replace($identity, ['sequence'=>3]), 1700000000), 'Stale witness sequence accepted');
check(!$verify($wire, array_replace($identity, ['event_hash'=>str_repeat('0',64)]), 1700000000),
    'Restored journal head accepted');
check(DigiForgePrintifyApi\HeldAuthorityAdapter::admissionStatus() === $boundary,
    'Evidence verification changed the direct admission boundary');
