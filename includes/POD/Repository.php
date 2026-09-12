<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    public function __construct(private ?Validator $validator = null)
    {
        $this->validator ??= new Validator();
    }

    public function createCatalog(array $input, ?string $key = null): array|WP_Error
    {
        $provider = sanitize_key((string)($input['provider'] ?? ''));
        $environment = sanitize_key((string)($input['environment'] ?? ''));
        $productKey = sanitize_text_field((string)($input['provider_product_key'] ?? ''));
        $variantKey = sanitize_text_field((string)($input['provider_variant_key'] ?? ''));
        if (! $this->validator->provider($provider) || ! $this->validator->environment($environment) || ! $this->validator->identifier($productKey)) {
            return $this->error('validation', 'Invalid provider catalog identity.');
        }
        if ($variantKey !== '' && ! $this->validator->identifier($variantKey)) {
            return $this->error('validation', 'Invalid provider variant identifier.');
        }
        $attributes = $this->validator->structured((array)($input['attributes'] ?? []));
        if (is_wp_error($attributes)) { return $attributes; }
        $shipping = $this->validator->structured((array)($input['shipping_profile'] ?? []));
        if (is_wp_error($shipping)) { return $shipping; }
        $cost = (float)($input['base_cost'] ?? 0);
        if (! $this->validator->nonNegativeMoney($cost)) { return $this->error('validation', 'Invalid base cost.'); }
        return $this->insert(Tables::pod_catalog(), $key, [
            'provider' => $provider,
            'environment' => $environment,
            'provider_product_key' => $productKey,
            'provider_variant_key' => $variantKey,
            'title' => sanitize_text_field((string)($input['title'] ?? '')),
            'variant_label' => sanitize_text_field((string)($input['variant_label'] ?? '')),
            'attributes' => wp_json_encode($attributes),
            'currency' => strtoupper(substr(sanitize_text_field((string)($input['currency'] ?? 'USD')), 0, 3)),
            'base_cost' => $cost,
            'shipping_profile' => wp_json_encode($shipping),
            'availability_state' => strtoupper(sanitize_key((string)($input['availability_state'] ?? 'UNKNOWN'))),
            'source_revision' => sanitize_text_field((string)($input['source_revision'] ?? '')),
            'observed_at' => isset($input['observed_at']) ? sanitize_text_field((string)$input['observed_at']) : null,
            'state' => 'DRAFT',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'pod_catalog');
    }

    public function createMapping(array $input, ?string $key = null): array|WP_Error
    {
        $pv = absint($input['product_version_id'] ?? 0);
        $planId = absint($input['production_plan_id'] ?? 0);
        $provider = sanitize_key((string)($input['provider'] ?? ''));
        $environment = sanitize_key((string)($input['environment'] ?? ''));
        $plan = $this->find(Tables::production_plans(), $planId);
        if ($pv < 1 || ! $this->exists(Tables::product_versions(), $pv) || ! is_array($plan) || (int)$plan['product_version_id'] !== $pv) {
            return $this->error('invalid_parent', 'Product version and production plan must exist and match.');
        }
        if (! $this->validator->provider($provider) || ! $this->validator->environment($environment)) {
            return $this->error('validation', 'Invalid provider or environment.');
        }
        if (! $this->integrationEnvironmentExists($provider, $environment)) {
            return $this->error('integration_environment_mismatch', 'Provider mapping environment must exist in the integration registry.', 409);
        }
        $productKey = sanitize_text_field((string)($input['provider_product_key'] ?? ''));
        $variantKey = sanitize_text_field((string)($input['provider_variant_key'] ?? ''));
        $version = sanitize_text_field((string)($input['mapping_version'] ?? ''));
        if (! $this->validator->identifier($productKey) || ($variantKey !== '' && ! $this->validator->identifier($variantKey)) || $version === '') {
            return $this->error('validation', 'Invalid provider mapping identity.');
        }
        return $this->insert(Tables::pod_mappings(), $key, [
            'product_version_id' => $pv,
            'production_plan_id' => $planId,
            'provider' => $provider,
            'environment' => $environment,
            'provider_product_key' => $productKey,
            'provider_variant_key' => $variantKey,
            'mapping_version' => $version,
            'state' => 'DRAFT',
            'notes' => sanitize_textarea_field((string)($input['notes'] ?? '')),
            'approved_by' => 0,
            'approved_at' => null,
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'pod_mapping');
    }

    public function createPrintArea(array $input, ?string $key = null): array|WP_Error
    {
        $mappingId = absint($input['provider_mapping_id'] ?? 0);
        $assetSpecId = absint($input['asset_spec_id'] ?? 0);
        $mapping = $this->find(Tables::pod_mappings(), $mappingId);
        $spec = $this->find(Tables::asset_specs(), $assetSpecId);
        if (! is_array($mapping) || ! is_array($spec) || (int)$mapping['product_version_id'] !== (int)$spec['product_version_id']) {
            return $this->error('invalid_relationship', 'Mapping and asset specification must exist and share a product version.', 409);
        }
        $width = (float)($input['width_value'] ?? 0);
        $height = (float)($input['height_value'] ?? 0);
        $dpi = absint($input['dpi_target'] ?? 0);
        $unit = sanitize_key((string)($input['unit'] ?? 'px'));
        $dimensionError = $this->validator->printDimensions($width, $height, $dpi, $unit);
        if ($dimensionError instanceof WP_Error) { return $dimensionError; }
        foreach (['bleed_metadata','safe_area_metadata','accepted_formats'] as $field) {
            $validated = $this->validator->structured((array)($input[$field] ?? []));
            if (is_wp_error($validated)) { return $validated; }
            $input[$field] = $validated;
        }
        $areaKey = sanitize_key((string)($input['area_key'] ?? ''));
        if ($areaKey === '') { return $this->error('validation', 'area_key is required.'); }
        return $this->insert(Tables::pod_print_areas(), $key, [
            'provider_mapping_id' => $mappingId,
            'asset_spec_id' => $assetSpecId,
            'area_key' => $areaKey,
            'placement' => sanitize_text_field((string)($input['placement'] ?? '')),
            'width_value' => $width,
            'height_value' => $height,
            'unit' => $unit,
            'dpi_target' => $dpi,
            'bleed_metadata' => wp_json_encode($input['bleed_metadata']),
            'safe_area_metadata' => wp_json_encode($input['safe_area_metadata']),
            'accepted_formats' => wp_json_encode($input['accepted_formats']),
            'background_policy' => sanitize_key((string)($input['background_policy'] ?? '')),
            'orientation_policy' => sanitize_key((string)($input['orientation_policy'] ?? '')),
            'state' => 'DRAFT',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'pod_print_area');
    }

    public function createPersonalizationSchema(array $input, ?string $key = null): array|WP_Error
    {
        $pv = absint($input['product_version_id'] ?? 0);
        if ($pv < 1 || ! $this->exists(Tables::product_versions(), $pv)) { return $this->error('invalid_parent', 'Valid product_version_id is required.'); }
        $schemaKey = sanitize_key((string)($input['schema_key'] ?? ''));
        $version = sanitize_text_field((string)($input['version_label'] ?? ''));
        if ($schemaKey === '' || $version === '') { return $this->error('validation', 'schema_key and version_label are required.'); }
        $fields = $this->validator->personalizationFields((array)($input['field_definitions'] ?? []));
        if (is_wp_error($fields)) { return $fields; }
        $structured = [];
        foreach (['normalization_rules','preview_instructions','policy_constraints'] as $field) {
            $value = $this->validator->structured((array)($input[$field] ?? []));
            if (is_wp_error($value)) { return $value; }
            $structured[$field] = $value;
        }
        return $this->insert(Tables::personalization_schemas(), $key, [
            'product_version_id' => $pv,
            'schema_key' => $schemaKey,
            'version_label' => $version,
            'field_definitions' => $this->validator->canonicalJson($fields),
            'normalization_rules' => $this->validator->canonicalJson($structured['normalization_rules']),
            'preview_instructions' => $this->validator->canonicalJson($structured['preview_instructions']),
            'policy_constraints' => $this->validator->canonicalJson($structured['policy_constraints']),
            'state' => 'DRAFT',
            'approved_by' => 0,
            'approved_at' => null,
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'personalization_schema');
    }

    public function createBinding(array $input, ?string $key = null): array|WP_Error
    {
        $schemaId = absint($input['personalization_schema_id'] ?? 0);
        $assetSpecId = absint($input['asset_spec_id'] ?? 0);
        $schema = $this->find(Tables::personalization_schemas(), $schemaId);
        $spec = $this->find(Tables::asset_specs(), $assetSpecId);
        if (! is_array($schema) || ! is_array($spec) || (int)$schema['product_version_id'] !== (int)$spec['product_version_id']) {
            return $this->error('invalid_relationship', 'Personalization schema and asset specification must share a product version.', 409);
        }
        $fieldKey = sanitize_key((string)($input['field_key'] ?? ''));
        $definitions = json_decode((string)$schema['field_definitions'], true);
        $keys = [];
        if (is_array($definitions)) {
            foreach ($definitions as $field) { if (is_array($field) && isset($field['key'])) { $keys[] = (string)$field['key']; } }
        }
        if ($fieldKey === '' || ! in_array($fieldKey, $keys, true)) { return $this->error('invalid_field', 'Binding field does not exist in personalization schema.'); }
        $instructions = $this->validator->structured((array)($input['instructions'] ?? []));
        if (is_wp_error($instructions)) { return $instructions; }
        return $this->insert(Tables::personalization_bindings(), $key, [
            'personalization_schema_id' => $schemaId,
            'asset_spec_id' => $assetSpecId,
            'field_key' => $fieldKey,
            'binding_type' => sanitize_key((string)($input['binding_type'] ?? 'asset_field')),
            'instructions' => wp_json_encode($instructions),
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'personalization_binding');
    }

    public function createIntent(array $input, ?string $key = null): array|WP_Error
    {
        $provider = sanitize_key((string)($input['provider'] ?? ''));
        $environment = sanitize_key((string)($input['environment'] ?? ''));
        $intent = strtoupper(sanitize_key((string)($input['intent_type'] ?? '')));
        $allowed = ['MAP_PRODUCT','MAP_VARIANT','PREPARE_PRINT_AREA','PREPARE_MOCKUP','PREPARE_UPLOAD','PREPARE_ORDER','PREPARE_PERSONALIZATION'];
        if (! $this->validator->provider($provider) || ! $this->validator->environment($environment) || ! in_array($intent, $allowed, true)) {
            return $this->error('validation', 'Invalid provider intent.');
        }
        $payload = $this->validator->structured((array)($input['input_payload'] ?? []));
        if (is_wp_error($payload)) { return $payload; }
        return $this->insert(Tables::pod_provider_intents(), $key, [
            'provider_mapping_id' => absint($input['provider_mapping_id'] ?? 0),
            'personalization_schema_id' => absint($input['personalization_schema_id'] ?? 0),
            'provider' => $provider,
            'environment' => $environment,
            'intent_type' => $intent,
            'input_payload' => wp_json_encode($payload),
            'state' => 'BLOCKED',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'pod_provider_intent');
    }

    public function createCostSnapshot(array $input, ?string $key = null): array|WP_Error
    {
        $mappingId = absint($input['provider_mapping_id'] ?? 0);
        if (! $this->exists(Tables::pod_mappings(), $mappingId)) { return $this->error('invalid_parent', 'Valid provider_mapping_id is required.'); }
        $base = (float)($input['base_production_cost'] ?? 0);
        $shipping = (float)($input['shipping_estimate'] ?? 0);
        if (! $this->validator->nonNegativeMoney($base) || ! $this->validator->nonNegativeMoney($shipping)) { return $this->error('validation', 'Invalid provider economics.'); }
        $fees = $this->validator->structured((array)($input['fee_metadata'] ?? []));
        if (is_wp_error($fees)) { return $fees; }
        $sourceType = sanitize_key((string)($input['source_type'] ?? 'manual'));
        if (! in_array($sourceType, ['manual','imported','future_provider'], true)) { return $this->error('validation', 'Invalid cost source type.'); }
        return $this->insert(Tables::pod_cost_snapshots(), $key, [
            'provider_mapping_id' => $mappingId,
            'currency' => strtoupper(substr(sanitize_text_field((string)($input['currency'] ?? 'USD')), 0, 3)),
            'base_production_cost' => $base,
            'shipping_estimate' => $shipping,
            'fee_metadata' => wp_json_encode($fees),
            'observed_at' => isset($input['observed_at']) ? sanitize_text_field((string)$input['observed_at']) : null,
            'source_type' => $sourceType,
            'state' => 'DRAFT',
            'created_by' => get_current_user_id(),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], 'pod_cost_snapshot');
    }

    public function transition(string $entity, int $id, string $to): array|WP_Error
    {
        $map = [
            'catalog' => [Tables::pod_catalog(), 'catalog'],
            'mapping' => [Tables::pod_mappings(), 'mapping'],
            'print_area' => [Tables::pod_print_areas(), 'print_area'],
            'personalization' => [Tables::personalization_schemas(), 'personalization'],
            'intent' => [Tables::pod_provider_intents(), 'intent'],
            'cost' => [Tables::pod_cost_snapshots(), 'cost'],
        ];
        if (! isset($map[$entity])) { return $this->error('validation', 'Unknown POD entity.'); }
        [$table, $kind] = $map[$entity];
        $row = $this->find($table, $id);
        if (! is_array($row)) { return $this->error('not_found', 'POD record not found.', 404); }
        $from = (string)$row['state'];
        $to = strtoupper(sanitize_key($to));
        if (! Lifecycle::can($kind, $from, $to)) { return $this->error('invalid_transition', 'Illegal POD state transition.', 409); }
        $data = ['state' => $to, 'updated_at' => $this->now()];
        if ($to === 'APPROVED' && in_array($kind, ['catalog','mapping','personalization','print_area','cost'], true)) {
            $uid = get_current_user_id();
            if ($uid < 1) { return $this->error('authorization', 'Approval requires an authenticated reviewer.', 403); }
            if (in_array($kind, ['mapping','personalization'], true)) { $data['approved_by'] = $uid; $data['approved_at'] = $this->now(); }
        }
        global $wpdb;
        $updated = $wpdb->update($table, $data, ['id' => $id, 'state' => $from]);
        if ($updated === false) { return $this->error('update_failed', 'Unable to update POD state.', 500); }
        if ($updated === 0) { return $this->error('state_conflict', 'POD state changed concurrently.', 409); }
        Logger::audit('pod_state_changed', ['entity' => $entity, 'from' => $from, 'to' => $to], $entity, (string)$id);
        return $this->find($table, $id) ?? $this->error('not_found', 'POD record not found.', 404);
    }

    public function readiness(int $mappingId): array|WP_Error
    {
        $mapping = $this->find(Tables::pod_mappings(), $mappingId);
        if (! is_array($mapping)) { return $this->error('not_found', 'Provider mapping not found.', 404); }
        global $wpdb;
        $areas = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::pod_print_areas() . " WHERE provider_mapping_id=%d AND state='APPROVED'", $mappingId));
        $plan = $this->find(Tables::production_plans(), (int)$mapping['production_plan_id']);
        $bundleCount = is_array($plan) ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::release_bundles() . " WHERE production_plan_id=%d AND state='RELEASE_READY'", (int)$plan['id'])) : 0;
        $personalizationRequired = false;
        $personalizationApproved = true;
        $schemaCount = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::personalization_schemas() . " WHERE product_version_id=%d", (int)$mapping['product_version_id']));
        if ($schemaCount > 0) {
            $personalizationRequired = true;
            $approvedCount = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Tables::personalization_schemas() . " WHERE product_version_id=%d AND state='APPROVED'", (int)$mapping['product_version_id']));
            $personalizationApproved = $approvedCount > 0;
        }
        $ready = ($mapping['state'] ?? '') === 'APPROVED' && $areas > 0 && $bundleCount > 0 && $personalizationApproved && (int)($mapping['approved_by'] ?? 0) > 0;
        $result = [
            'ready' => $ready,
            'mapping_approved' => ($mapping['state'] ?? '') === 'APPROVED',
            'approved_print_areas' => $areas,
            'release_ready_bundles' => $bundleCount,
            'personalization_required' => $personalizationRequired,
            'personalization_approved' => $personalizationApproved,
            'human_approved' => (int)($mapping['approved_by'] ?? 0) > 0,
        ];
        Logger::audit('pod_readiness_evaluated', $result, 'pod_mapping', (string)$mappingId);
        return $result;
    }

    public function list(string $entity, int $page = 1, int $perPage = 20): array
    {
        $map = [
            'catalog' => Tables::pod_catalog(),
            'mappings' => Tables::pod_mappings(),
            'print_areas' => Tables::pod_print_areas(),
            'personalization' => Tables::personalization_schemas(),
            'bindings' => Tables::personalization_bindings(),
            'intents' => Tables::pod_provider_intents(),
            'costs' => Tables::pod_cost_snapshots(),
        ];
        if (! isset($map[$entity])) { return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => 20, 'total_items' => 0, 'total_pages' => 0]]; }
        global $wpdb;
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $total = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . $map[$entity]);
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $map[$entity] . ' ORDER BY id DESC LIMIT %d OFFSET %d', $perPage, $offset), ARRAY_A) ?: [];
        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $total, 'total_pages' => (int)ceil($total / $perPage)]];
    }

    private function integrationEnvironmentExists(string $provider, string $environment): bool
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Tables::integrations() . ' WHERE provider=%s AND environment=%s', $provider, $environment)) > 0;
    }

    private function insert(string $table, ?string $key, array $data, string $objectType): array|WP_Error
    {
        global $wpdb;
        if ($key !== null && $key !== '') {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1", $key), ARRAY_A);
            if (is_array($existing)) { return $existing; }
            $data['idempotency_key'] = substr($key, 0, 191);
        }
        $ok = $wpdb->insert($table, $data);
        if ($ok === false) { return $this->error('create_failed', 'Unable to create POD record.', 500); }
        $id = (int)$wpdb->insert_id;
        Logger::audit($objectType . '_created', ['id' => $id], $objectType, (string)$id);
        return $this->find($table, $id) ?? $this->error('create_failed', 'Unable to read POD record.', 500);
    }

    private function find(string $table, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function exists(string $table, int $id): bool
    {
        if ($id < 1) { return false; }
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d", $id)) > 0;
    }

    private function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('digiforge_' . $code, $message, ['status' => $status]);
    }

    private function now(): string
    {
        return current_time('mysql', true);
    }
}
