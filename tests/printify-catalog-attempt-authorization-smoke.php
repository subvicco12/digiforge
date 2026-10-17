<?php
declare(strict_types=1);
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $r): int { return $r['status']; }
function wp_remote_retrieve_header(array $r, string $name): string { return $r['retry_after'] ?? ''; }
function wp_remote_retrieve_body(array $r): string { return $r['body'] ?? ''; }
require dirname(__DIR__) . '/includes/POD/PrintifyCatalogClient.php';
use DigiForge\POD\PrintifyCatalogClient;
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$calls = 0;
$authorized = 0;
$sender = static function (string $url, array $args) use (&$calls): array {
    ++$calls;
    return $calls === 1 ? ['status'=>429,'retry_after'=>'0'] : ['status'=>200,'body'=>'{"variants":[]}'];
};
$guard = static function (string $url, int $attempt) use (&$authorized): bool {
    ++$authorized;
    return $attempt === 1;
};
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, $guard);
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $result->code === 'digiforge_printify_read_authorization', 'Retry must be denied');
ensure($calls === 1 && $authorized === 2, 'No second transport after denied retry');

$calls = 0;
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, static fn(): WP_Error => new WP_Error('locked', 'STOP ALL'));
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $result->code === 'locked' && $calls === 0, 'Explicit denial must precede network');
$client = new PrintifyCatalogClient($sender, static fn(int $delay): null => null, static function (): bool { throw new RuntimeException('failure'); });
$result = $client->variants('test-token', 478, 28);
ensure($result instanceof WP_Error && $calls === 0, 'Guard exceptions must deny before network');
echo "Printify catalog attempt authorization offline tests passed\n";
