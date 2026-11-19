<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

// Any exercise of these WordPress interfaces during bootstrap is a regression.
function add_action(...$args): void { $GLOBALS['fixture_hooks'][] = $args; }
function add_management_page(...$args): void { $GLOBALS['fixture_menu'] = $args; }
function current_user_can(string $cap): bool { return in_array($cap, $GLOBALS['fixture_caps'], true); }
function wp_get_environment_type(): string { return $GLOBALS['fixture_environment'] ?? 'production'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function add_filter(...$args): never { throw new RuntimeException('Unexpected filter'); }
function register_activation_hook(...$args): never { throw new RuntimeException('Unexpected activation hook'); }
function register_rest_route(...$args): never { throw new RuntimeException('Unexpected route'); }
function wp_remote_request(...$args): never { throw new RuntimeException('Unexpected network request'); }
function get_option(...$args): never { throw new RuntimeException('Unexpected option access'); }
function update_option(...$args): never { throw new RuntimeException('Unexpected option write'); }

$GLOBALS['fixture_hooks'] = [];
$GLOBALS['fixture_caps'] = [];
define('DIGIFORGE_PRINTIFY_OFFLINE_SHOP', '107');
define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT', 'fixture-account');
define('ABSPATH', __DIR__ . '/synthetic-wordpress/');
$plugin = $argv[1] ?? __DIR__ . '/../digiforge-printify-api/digiforge-printify-api.php';
$autoloaders = spl_autoload_functions();
ob_start();
require $plugin;
require $plugin;
check(ob_get_clean() === '', 'Bootstrap emitted output');
check(spl_autoload_functions() === $autoloaders, 'Bootstrap registered an autoloader');
check(!class_exists('DigiForge\\Core\\Plugin', false), 'Bootstrap loaded Core');
$status = DigiForgePrintifyApi\Status::snapshot();
check($status === [
    'schema' => 'DF_STANDALONE_PRINTIFY_STATUS_1',
    'version' => '0.8.0',
    'mode' => 'PASSIVE_OFFLINE',
    'capabilities' => [],
    'stop_all' => true,
    'external_lock' => true,
    'automation' => false,
    'release_hold' => true,
    'provider_execution_authorized' => false,
], 'Unexpected diagnostic state');
$status['automation'] = true;
check(DigiForgePrintifyApi\Status::snapshot()['automation'] === false, 'Status mutation escaped');
use DigiForgePrintifyApi\Runtime;
check($GLOBALS['fixture_hooks'] === [['admin_menu', [Runtime::class, 'menu']]], 'Unexpected bootstrap hooks');
Runtime::menu();
check($GLOBALS['fixture_menu'][2] === 'manage_options', 'Missing operator gate');
ob_start();
Runtime::panel();
check(ob_get_clean() === '', 'Unauthorized operator saw diagnostics');
check(Runtime::discover()['capabilities'] === [], 'Unauthorized discovery');
$GLOBALS['fixture_caps'] = ['manage_options'];
ob_start();
Runtime::panel();
check(str_contains(ob_get_clean(), 'Provider dispatch'), 'Diagnostics panel missing');
check(Runtime::discover()['capabilities'] === ['PANEL/DIAGNOSTICS'], 'Unscoped catalog advertised');
$GLOBALS['fixture_caps'][] = 'digiforge_printify_review_shop_107';
if (is_file(dirname($plugin) . '/Engine/PrintifyCatalogReadContract.php')) {
    $withLifecycle = ($argv[2] ?? '') !== 'missing-lifecycle';
    $capabilities = ['PANEL/DIAGNOSTICS', 'OFFLINE_CATALOG_VALIDATE', 'OFFLINE_PROVIDER_READ_REVIEW'];
    if ($withLifecycle) {
        $capabilities[] = 'OFFLINE_LIFECYCLE_REVIEW';
    }
    check(Runtime::discover()['capabilities'] === $capabilities, 'Unexpected packaged capabilities');
    $bp = ['kind' => 'blueprint', 'blueprint_id' => 42];
    $fixtures = [
        [['kind' => 'blueprints'], [['id' => 42]]],
        [$bp, ['id' => 42]],
        [['kind' => 'providers', 'blueprint_id' => 42], [['id' => 2]]],
        [['kind' => 'provider', 'blueprint_id' => 42, 'provider_id' => 2],
            ['id' => 2, 'title' => 'Synthetic', 'decoration_methods' => [], 'production_time' => null]],
        [['kind' => 'variants', 'blueprint_id' => 42, 'provider_id' => 2], ['variants' => [['id' => 17]]]],
        [['kind' => 'variant', 'blueprint_id' => 42, 'provider_id' => 2, 'variant_id' => 17], ['variants' => [['id' => 17]]]],
        [['kind' => 'size_guide', 'blueprint_id' => 42], ['sizes' => [], 'types' => []]],
        [['kind' => 'shipping', 'blueprint_id' => 42, 'provider_id' => 2], ['data' => []]],
        [['kind' => 'shipping_method', 'blueprint_id' => 42, 'provider_id' => 2, 'method' => 'standard'], ['data' => []]],
    ];
    foreach ($fixtures as [$request, $response]) {
        $bytes = json_encode($response, JSON_THROW_ON_ERROR);
        $result = Runtime::validateCatalog('107', $request, $bytes);
        check($result['state'] === 'LOCAL_VALIDATION_PASSED', 'Contract denied ' . $request['kind']);
        check($result['snapshot_sha256'] === hash('sha256', $bytes), 'Snapshot digest differs');
        check($result['freshness_verified'] === false && $result['provider_execution_authorized'] === false, 'Authority fabricated');
    }
    check(Runtime::validateCatalog('108', $bp, '{"id":42}')['state'] === 'DENIED', 'Wrong shop accepted');
    check(Runtime::validateCatalog('107', $bp, '{"id":41}')['state'] === 'DENIED', 'Wrong identity accepted');
    check(Runtime::validateCatalog('107', $bp, '{"id":42,"last_page":2}')['state'] === 'DENIED', 'Partial pagination accepted');
    check(Runtime::validateCatalog('107', $bp, str_repeat(' ', 262145))['state'] === 'DENIED', 'Oversize body accepted');
    check(Runtime::validateCatalog('107', $bp, '{broken')['state'] === 'DENIED', 'Malformed body accepted');
    require __DIR__.'/provider-routing-fixture.php';
    require __DIR__.'/native-authority-fixture.php';
    if ($withLifecycle) {
        require __DIR__.'/lifecycle-fixture.php';
        require __DIR__.'/synthetic-journey-fixture.php';
    } else {
        check(Runtime::reviewLifecycle('107', ['account_reference'=>'fixture-account'], [])['state'] === 'DENIED',
            'Missing lifecycle dependency did not deny direct invocation');
    }
    check(!class_exists('DigiForge\\POD\\PrintifyCatalogReadContract', false), 'Loaded a Core symbol');
} else {
    check(Runtime::discover()['capabilities'] === ['PANEL/DIAGNOSTICS'], 'Missing engine was advertised');
}
echo "Standalone bootstrap and capability smoke PASS\n";
