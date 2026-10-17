<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Core\Settings;
use WP_Error;

/**
 * Catalog admission delegates to the existing grant repository; it never grants
 * authority from global feature flags or a caller-provided callback. Legacy
 * callback-only previews always deny. Scoped exceptions remain grant-bound and
 * cannot alter STOP ALL, external lock or general connector semantics.
 */
final class PrintifyCatalogReadPolicy
{
    public static function authorizeReserved(array $authority, array $request, string $token, bool $consume): true|WP_Error
    {
        try { return (new PrintifyCatalogReadRepository())->authorizeReservedAttempt($authority, $request, $token, $consume); }
        catch (\Throwable) { return new WP_Error('catalog_read_authorization', 'Scoped catalog admission unavailable.', ['status'=>409]); }
    }

    public static function authorize(int $blueprintId, int $providerId): WP_Error
    {
        if ($blueprintId < 1 || $providerId < 1) {
            return new WP_Error('digiforge_printify_catalog_identity', 'Positive catalog identifiers required.', ['status' => 400]);
        }
        if (Settings::safety_locked()
            || Settings::get('stop_all', true) !== false
            || Settings::get('printify_activation_authorized', false) !== true
            || Settings::is_enabled('printify') !== true
            || Settings::is_enabled('research') !== true) {
            return new WP_Error('digiforge_printify_catalog_locked', 'Catalog reads remain locked by authorization policy.', ['status' => 409]);
        }
        return new WP_Error('digiforge_printify_catalog_scope_required', 'An existing scoped catalog grant and durable reservation are required.', ['status'=>403]);
    }
}
