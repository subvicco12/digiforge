<?php
declare(strict_types=1);
/** Offline read-only request: never creates roots, signatures or enrollment secrets. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
final class WP_Error {
    public function __construct(public string $code, public string $message = '', public mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
require_once __DIR__.'/../includes/Core/Capabilities.php';
require_once __DIR__.'/../includes/Security/ShopAccessPolicy.php';
require_once __DIR__.'/../includes/Security/ShopAccessAuthority.php';
require_once __DIR__.'/../includes/PortalAccess/Owner.php';
require_once __DIR__.'/../includes/PortalAccess/GlobalOwnerAuthority.php';

/** Bound reads; refuse links, devices and replacement between lstat and open. */
function readPublicInput(string $path, int $limit): string {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > $limit) {
        throw new RuntimeException('Input must be a bounded regular non-symlink file.');
    }
    $stream = @fopen($path, 'rb');
    if ($stream === false) { throw new RuntimeException('Cannot read public input.'); }
    try {
        $opened = fstat($stream);
        if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || $opened['size'] > $limit) {
            throw new RuntimeException('Input changed while opening.');
        }
        $raw = stream_get_contents($stream, $limit + 1);
        if (!is_string($raw) || strlen($raw) > $limit) { throw new RuntimeException('Input exceeds limit.'); }
        return $raw;
    } finally { fclose($stream); }
}
try {
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--(trust-key-file|authority-file|site|approval|expires)=(.+)$/Ds', $arg, $match) || isset($args[$match[1]])) {
            throw new RuntimeException('Use unique --trust-key-file=PUBLIC_FILE [--authority-file=SIGNED_ENVELOPE] --site=https://host/ --approval=64_lowercase_hex --expires=UTC_epoch_within_30_days.');
        }
        $args[$match[1]] = $match[2];
    }
    foreach (['trust-key-file','site','approval','expires'] as $required) {
        if (!isset($args[$required])) { throw new RuntimeException('Missing required public planning input: '.$required); }
    }
    $site = $args['site']; $url = parse_url($site); $now = time();
    if (filter_var($site, FILTER_VALIDATE_URL) === false || !is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !str_ends_with($site, '/') || preg_match('/[\x00-\x20\x7f]/', $site)) {
        throw new RuntimeException('Site must be an exact HTTPS URL with trailing slash and no userinfo, query or fragment.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $args['approval']) || !ctype_digit($args['expires']) || strlen($args['expires']) > 10 || (int)$args['expires'] <= $now || (int)$args['expires'] > $now + 30*86400) {
        throw new RuntimeException('Invalid approval hash or expiry; expiry must be future and within 30 days.');
    }
    $publicText = trim(readPublicInput($args['trust-key-file'], 256));
    $public = base64_decode($publicText, true);
    if (!is_string($public) || strlen($public) !== 32 || base64_encode($public) !== $publicText || !function_exists('sodium_crypto_sign_verify_detached')) {
        throw new RuntimeException('Existing trust key must be canonical base64 Ed25519 public key (32 bytes); root rotation is unsupported.');
    }
    $records = []; $classification = 'REQUIRES_SIGNED_GLOBAL_REPLACEMENT';
    if (isset($args['authority-file'])) {
        $envelope = json_decode(readPublicInput($args['authority-file'], 131072), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || !is_string($envelope['manifest'] ?? null) || !is_string($envelope['signature'] ?? null)) { throw new RuntimeException('Malformed authority envelope.'); }
        $records = \DigiForge\Security\ShopAccessAuthority::verify($envelope['manifest'], $envelope['signature'], $publicText, $now);
        if (is_wp_error($records)) { throw new RuntimeException('Existing authority signature, schema or expiry is invalid.'); }
        $hasGlobal = false;
        foreach ($records as $record) {
            if ($record['authority'] === 'OWNER' && $record['actor_id'] !== 1) { throw new RuntimeException('Conflicting OWNER actor; independent review required.'); }
            if ($record['authority'] === 'OWNER' && (isset($record['owner_scope']) || isset($record['global_authority_id']))) { $hasGlobal = true; }
        }
        if ($hasGlobal) {
            $resolved = \DigiForge\PortalAccess\GlobalOwnerAuthority::resolve($records, $site, $now);
            if (is_wp_error($resolved)) { throw new RuntimeException('Existing global authority is incompatible with the exact site or global owner schema.'); }
            $classification = 'CURRENT_GLOBAL_AUTHORITY';
        }
    }
    $delegates = array_values(array_filter($records, static fn(array $record): bool => $record['authority'] === 'DELEGATE'));
    $owners = \DigiForge\PortalAccess\GlobalOwnerAuthority::draft($site, (int)$args['expires'], $args['approval']);
    $manifest = ['version'=>1, 'authorities'=>array_merge($owners, $delegates)];
    $manifestText = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($manifestText) > 65536 || !\DigiForge\Security\ShopAccessAuthority::validAuthorities($manifest['authorities'], $now)) {
        throw new RuntimeException('Preserving delegates would exceed the existing host authority limits; independent review required.');
    }
    echo json_encode([
        'status'=>'UNSIGNED_NOT_PROVISIONED', 'classification'=>$classification,
        'root_rotation_supported'=>false, 'trusted_public_key_sha256'=>hash('sha256', $public),
        'manifest'=>$manifestText,
        'host_compatibility_plan'=>[
            'site'=>$site, 'preserve_trusted_public_root'=>true, 'preserved_delegate_count'=>count($delegates),
            'signing'=>'Trusted custodian must sign the exact manifest string with the existing trusted Ed25519 root. This unsigned request establishes no authority.',
            'authority_installation'=>'After separate review, replace legacy OWNER records with all four signed global OWNER records and preserve verified DELEGATE records unchanged.',
            'grant_history'=>'Retain existing profiles and grant audit history. Replacing OWNER authority invalidates prior authority-bound grants; separately reviewed regrant is required, with no silent reauthorization.',
            'configuration'=>'Retain existing site and enrollment code configuration untouched until separately approved; do not rotate the trust root.',
            'required_reviewed_constants'=>[
                'DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SCOPE'=>'GLOBAL_PRIMARY_OWNER',
                'DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SITE'=>$site,
            ],
            'later_scope_bound_configuration'=>['DIGIFORGE_PORTAL_OWNER_ENROLLMENT_SHA256','DIGIFORGE_PORTAL_OWNER_RECOVERY_SHA256','DIGIFORGE_PORTAL_OWNER_RECOVERY_SCOPE_SHA256'],
            'recovery'=>'A separately reviewed scope-bound enrollment code is required later. This planner does not generate or install codes, secrets, signatures, host configuration or enrollment.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Global owner planning failed: '.$error->getMessage()."\n"); exit(1);
}
