<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use WP_Error;

/** Writes business-active POD ownership only after registry resolution. */
final class BusinessScopeRepository
{
    public function createMapping(array $input, ?string $idempotencyKey = null): array|WP_Error
    {
        global $wpdb;
        try {
            $scope = BusinessScope::resolveConfigured($input);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('digiforge_scope_validation', $e->getMessage(), ['status' => 409]);
        }

        $productVersionId = absint($input['product_version_id'] ?? 0);
        $providerMappingId = absint($input['provider_mapping_id'] ?? 0);
        if ($productVersionId < 1 || $providerMappingId < 1) {
            return new WP_Error('digiforge_scope_validation', 'product_version_id and provider_mapping_id are required.', ['status' => 400]);
        }
        $providerMapping = $wpdb->get_row($wpdb->prepare(
            'SELECT product_version_id FROM ' . Tables::pod_mappings() . ' WHERE id=%d LIMIT 1',
            $providerMappingId
        ), ARRAY_A);
        if (!is_array($providerMapping) || (int) ($providerMapping['product_version_id'] ?? 0) !== $productVersionId) {
            return new WP_Error('digiforge_scope_relationship', 'Provider mapping must exist and match the product version.', ['status' => 409]);
        }

        $key = $idempotencyKey === null ? null : sanitize_text_field($idempotencyKey);
        if ($key === '') {
            $key = null;
        }
        if ($key !== null) {
            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE idempotency_key=%s LIMIT 1',
                $key
            ), ARRAY_A);
            if (is_array($existing)) {
                return $existing;
            }
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(Tables::pod_business_mappings(), [
            'business_id' => $scope['business_id'],
            'store_id' => $scope['store_id'],
            'product_program_id' => $scope['product_program_id'],
            'product_version_id' => $productVersionId,
            'provider_mapping_id' => $providerMappingId,
            'state' => 'DRAFT',
            'idempotency_key' => $key,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return new WP_Error('digiforge_scope_create_failed', 'Unable to create scoped POD mapping.', ['status' => 500]);
        }

        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE id=%d',
            (int) $wpdb->insert_id
        ), ARRAY_A);
    }
}
