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
    /** @return array{business_id:int,store_id:int,product_program_id:int,product_program:string}|WP_Error */
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

        // DigiCraftifyGoods remains PERSONALIZED_POD only. The future original-
        // design business must be configured as a separate business/store.
        if (($resolved['product_program'] ?? '') === BusinessScope::ORIGINAL_DESIGN_POD) {
            $business = sanitize_key((string)($scope['business_id'] ?? ''));
            if ($business === BusinessScope::DIGICRAFTIFY_GOODS) {
                return new WP_Error('digiforge_pod_program_boundary', 'DigiCraftifyGoods cannot activate ORIGINAL_DESIGN_POD.', ['status' => 409]);
            }
        }

        return true;
    }
}
