<?php
declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Core\Settings;
use WP_Error;

/**
 * Independent catalog-GET preflight. Does not fetch credentials, issue HTTP,
 * alter switches, or authorize Printify mutations.
 *
 * This policy is intentionally inactive while STOP ALL or the external safety
 * lock is active. A passing decision is not a network permit: callers must
 * independently enforce scoped credentials, endpoint allowlisting and budgets.
 */
final class PrintifyCatalogReadPolicy
{
    public static function authorize(int $blueprintId, int $providerId): bool|WP_Error
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
        return true;
    }
}
