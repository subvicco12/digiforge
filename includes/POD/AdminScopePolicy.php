<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Central policy for DigiForge's customer-facing POD admin controls.
 * It validates configured ownership but performs no publishing/provider action.
 */
final class AdminScopePolicy
{
    /** @return array{business_id:int,store_id:int,product_program_id:int,product_program:string,business_key?:string}|WP_Error */
    public static function resolve(array $input): array|WP_Error
    {
        if (! current_user_can('manage_digiforge_pod')) {
            return new WP_Error('digiforge_pod_admin_forbidden', 'POD administration permission is required.', ['status' => 403]);
        }

        try {
            return BusinessScope::resolveConfigured($input);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('digiforge_pod_admin_scope', $e->getMessage(), ['status' => 409]);
        }
    }

    public static function canActivateProgram(array $scope): true|WP_Error
    {
        $resolved = self::resolve($scope);
        if (is_wp_error($resolved)) return $resolved;

        /* The authoritative restriction is enforced by BusinessScope against
         * the resolved ACTIVE business record. Do not re-derive ownership from
         * the caller's raw business_id, which may legitimately be numeric. */
        if (($resolved['product_program'] ?? '') === BusinessScope::ORIGINAL_DESIGN_POD
            && ($resolved['business_key'] ?? '') === BusinessScope::DIGICRAFTIFY_GOODS) {
            return new WP_Error('digiforge_pod_program_boundary', 'DigiCraftifyGoods cannot activate ORIGINAL_DESIGN_POD.', ['status' => 409]);
        }

        return true;
    }
}
