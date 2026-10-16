<?php
declare(strict_types=1);

/** Standalone offline smoke test: php tests/printify-catalog-preview-smoke.php */
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }

require_once dirname(__DIR__) . '/includes/POD/PrintifyCatalogPreview.php';

use DigiForge\POD\PrintifyCatalogPreview;

$reads = 0;
$variants = static function (int $blueprint, int $provider) use (&$reads): array {
    ++$reads;
    return ['variants' => [
        ['id' => 123, 'title' => '11oz', 'is_available' => true, 'cost' => 503],
        ['id' => 456, 'title' => '15oz', 'is_available' => false, 'cost' => 613],
    ]];
};
$shipping = static function (int $blueprint, int $provider) use (&$reads): array {
    ++$reads;
    return ['private_or_unvalidated' => 'do not expose'];
};
$invalid = PrintifyCatalogPreview::inspect(0, 28, $variants, $shipping);
assert($invalid instanceof WP_Error);
assert($reads === 0);
$ok = PrintifyCatalogPreview::inspect(478, 28, $variants, $shipping);
assert(is_array($ok));
assert($reads === 2);
assert($ok['persistence'] === 'NONE');
assert($ok['certification'] === 'NOT_CERTIFIED');
assert($ok['variants'][0]['variant_id'] === 123);
assert($ok['variants'][0]['print_area'] === null);
assert($ok['variants'][0]['geometry_status'] === 'UNKNOWN');
assert($ok['shipping_country_rates'] === null);
assert(strpos(json_encode($ok, JSON_THROW_ON_ERROR), 'do not expose') === false);
$bad = PrintifyCatalogPreview::inspect(478, 28, static fn() => ['variants' => [['id' => '123']]], $shipping);
assert($bad instanceof WP_Error);
assert($bad->code === 'digiforge_printify_preview_variant');
$transportError = new WP_Error('offline', 'blocked');
$failed = PrintifyCatalogPreview::inspect(478, 28, static fn() => $transportError, $shipping);
assert($failed === $transportError);
echo "Printify catalog preview offline smoke passed\n";
