<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

// Any exercise of these WordPress interfaces during bootstrap is a regression.
function add_action(...$args): never { throw new RuntimeException('Unexpected hook'); }
function add_filter(...$args): never { throw new RuntimeException('Unexpected filter'); }
function register_activation_hook(...$args): never { throw new RuntimeException('Unexpected activation hook'); }
function register_rest_route(...$args): never { throw new RuntimeException('Unexpected route'); }
function wp_remote_request(...$args): never { throw new RuntimeException('Unexpected network request'); }
function get_option(...$args): never { throw new RuntimeException('Unexpected option access'); }
function update_option(...$args): never { throw new RuntimeException('Unexpected option write'); }

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
    'version' => '0.1.0',
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
echo "Standalone bootstrap smoke PASS\n";
