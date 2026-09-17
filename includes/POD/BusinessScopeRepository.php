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

        $key = $idempotencyKey === null ? null : sanitize_text_field($idempotencyKey);
        if ($key === '') {
            $key = null;
        }

        $wpdb->query('START TRANSACTION');
        try {
            // Lock the complete ACTIVE hierarchy so a concurrent admin change
            // cannot deactivate or reparent the scope between validation and
            // insertion of business-active ownership.
            $business = $wpdb->get_row($wpdb->prepare(
                'SELECT id FROM ' . Tables::businesses() . ' WHERE id=%d AND status=%s FOR UPDATE',
                $scope['business_id'],
                'ACTIVE'
            ), ARRAY_A);
            $store = $wpdb->get_row($wpdb->prepare(
                'SELECT id FROM ' . Tables::stores() . ' WHERE id=%d AND business_id=%d AND status=%s FOR UPDATE',
                $scope['store_id'],
                $scope['business_id'],
                'ACTIVE'
            ), ARRAY_A);
            $program = $wpdb->get_row($wpdb->prepare(
                'SELECT id FROM ' . Tables::product_programs() . ' WHERE id=%d AND business_id=%d AND store_id=%d AND status=%s FOR UPDATE',
                $scope['product_program_id'],
                $scope['business_id'],
                $scope['store_id'],
                'ACTIVE'
            ), ARRAY_A);
            if (!is_array($business) || !is_array($store) || !is_array($program)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('digiforge_scope_inactive', 'Business/store/product-program ownership is no longer active.', ['status' => 409]);
            }

            $providerMapping = $wpdb->get_row($wpdb->prepare(
                'SELECT product_version_id FROM ' . Tables::pod_mappings() . ' WHERE id=%d FOR UPDATE',
                $providerMappingId
            ), ARRAY_A);
            if (!is_array($providerMapping) || (int) ($providerMapping['product_version_id'] ?? 0) !== $productVersionId) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('digiforge_scope_relationship', 'Provider mapping must exist and match the product version.', ['status' => 409]);
            }

            if ($key !== null) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE idempotency_key=%s LIMIT 1 FOR UPDATE',
                    $key
                ), ARRAY_A);
                if (is_array($existing)) {
                    $sameRequest =
                        (int) ($existing['business_id'] ?? 0) === $scope['business_id'] &&
                        (int) ($existing['store_id'] ?? 0) === $scope['store_id'] &&
                        (int) ($existing['product_program_id'] ?? 0) === $scope['product_program_id'] &&
                        (int) ($existing['product_version_id'] ?? 0) === $productVersionId &&
                        (int) ($existing['provider_mapping_id'] ?? 0) === $providerMappingId;
                    if (!$sameRequest) {
                        $wpdb->query('ROLLBACK');
                        return new WP_Error('digiforge_idempotency_conflict', 'Idempotency key is already bound to a different business scope or mapping.', ['status' => 409]);
                    }
                    $wpdb->query('COMMIT');
                    return $existing;
                }
            }

            // A product-version/provider-mapping pair is business-active data,
            // not shared catalog evidence. It receives one immutable owner.
            $owner = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE product_version_id=%d AND provider_mapping_id=%d LIMIT 1 FOR UPDATE',
                $productVersionId,
                $providerMappingId
            ), ARRAY_A);
            if (is_array($owner)) {
                $sameOwner =
                    (int) ($owner['business_id'] ?? 0) === $scope['business_id'] &&
                    (int) ($owner['store_id'] ?? 0) === $scope['store_id'] &&
                    (int) ($owner['product_program_id'] ?? 0) === $scope['product_program_id'];
                $wpdb->query('ROLLBACK');
                return new WP_Error(
                    $sameOwner ? 'digiforge_scope_mapping_exists' : 'digiforge_scope_ownership_conflict',
                    $sameOwner ? 'Scoped POD mapping already exists.' : 'Product/provider mapping is owned by a different business scope.',
                    ['status' => 409]
                );
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
                $wpdb->query('ROLLBACK');
                return new WP_Error('digiforge_scope_create_failed', 'Unable to create scoped POD mapping.', ['status' => 500]);
            }

            $created = (array) $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . Tables::pod_business_mappings() . ' WHERE id=%d',
                (int) $wpdb->insert_id
            ), ARRAY_A);
            $wpdb->query('COMMIT');
            return $created;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('digiforge_scope_create_failed', 'Unable to create scoped POD mapping.', ['status' => 500]);
        }
    }
}
