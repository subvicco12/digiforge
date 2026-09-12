<?php

declare(strict_types=1);

namespace DigiForge\AI;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

final class Repository
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;

    public function createTask(array $input, ?string $key = null): array|\WP_Error
    {
        $taskKey = sanitize_key((string) ($input['task_key'] ?? ''));
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if ($taskKey === '' || $name === '') {
            return $this->error('validation', 'task_key and name are required.');
        }
        $capabilities = $this->encode($input['required_capabilities'] ?? []);
        if (is_wp_error($capabilities)) {
            return $capabilities;
        }
        return $this->insert(Tables::ai_tasks(), $key, [
            'task_key' => $taskKey,
            'name' => $name,
            'environment' => $this->environment($input['environment'] ?? 'sandbox'),
            'required_capabilities' => $capabilities,
            'min_quality_tier' => max(0, (int) ($input['min_quality_tier'] ?? 0)),
            'max_latency_ms' => max(0, (int) ($input['max_latency_ms'] ?? 0)),
            'max_cost_per_1k' => max(0, (float) ($input['max_cost_per_1k'] ?? 0)),
            'status' => 'ACTIVE',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], 'ai_task');
    }

    public function createModel(array $input, ?string $key = null): array|\WP_Error
    {
        $modelKey = sanitize_key((string) ($input['model_key'] ?? ''));
        $provider = sanitize_key((string) ($input['provider'] ?? ''));
        if ($modelKey === '' || $provider === '') {
            return $this->error('validation', 'model_key and provider are required.');
        }
        $capabilities = $this->encode($input['capabilities'] ?? []);
        if (is_wp_error($capabilities)) {
            return $capabilities;
        }
        return $this->insert(Tables::ai_models(), $key, [
            'model_key' => $modelKey,
            'provider' => $provider,
            'environment' => $this->environment($input['environment'] ?? 'sandbox'),
            'model_class' => sanitize_key((string) ($input['model_class'] ?? 'text')),
            'capabilities' => $capabilities,
            'quality_tier' => max(0, (int) ($input['quality_tier'] ?? 0)),
            'latency_ms' => max(0, (int) ($input['latency_ms'] ?? 0)),
            'cost_per_1k' => max(0, (float) ($input['cost_per_1k'] ?? 0)),
            'fallback_order' => max(0, (int) ($input['fallback_order'] ?? 100)),
            'prohibited' => ! empty($input['prohibited']) ? 1 : 0,
            'enabled' => ! empty($input['enabled']) ? 1 : 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], 'ai_model');
    }

    public function createPrompt(array $input, ?string $key = null): array|\WP_Error
    {
        $promptKey = sanitize_key((string) ($input['prompt_key'] ?? ''));
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if ($promptKey === '' || $name === '') {
            return $this->error('validation', 'prompt_key and name are required.');
        }
        return $this->insert(Tables::ai_prompts(), $key, [
            'prompt_key' => $promptKey,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], 'ai_prompt');
    }

    public function createPromptVersion(int $promptId, array $input, ?string $key = null): array|\WP_Error
    {
        if ($this->find(Tables::ai_prompts(), $promptId) === null) {
            return $this->error('not_found', 'Prompt not found.', 404);
        }
        $version = sanitize_text_field((string) ($input['version_label'] ?? ''));
        if ($version === '') {
            return $this->error('validation', 'version_label is required.');
        }
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Tables::ai_prompt_versions() . ' WHERE prompt_id=%d AND version_label=%s',
            $promptId,
            $version
        ));
        if ($existing !== null) {
            return $this->error('immutable_version', 'Prompt versions are immutable; create a new version label.', 409);
        }

        $inputSchema = $this->encode($input['input_schema'] ?? []);
        if (is_wp_error($inputSchema)) {
            return $inputSchema;
        }
        $outputSchema = $this->encode($input['output_schema'] ?? []);
        if (is_wp_error($outputSchema)) {
            return $outputSchema;
        }
        $system = sanitize_textarea_field((string) ($input['system_text'] ?? ''));
        $instruction = sanitize_textarea_field((string) ($input['instruction_text'] ?? ''));
        $template = sanitize_textarea_field((string) ($input['template_text'] ?? ''));
        $checksum = hash('sha256', $promptId . '|' . $version . '|' . $system . '|' . $instruction . '|' . $template . '|' . $inputSchema . '|' . $outputSchema);

        return $this->insert(Tables::ai_prompt_versions(), $key, [
            'prompt_id' => $promptId,
            'version_label' => $version,
            'system_text' => $system,
            'instruction_text' => $instruction,
            'template_text' => $template,
            'input_schema_id' => sanitize_key((string) ($input['input_schema_id'] ?? '')),
            'input_schema_version' => sanitize_text_field((string) ($input['input_schema_version'] ?? '')),
            'output_schema_id' => sanitize_key((string) ($input['output_schema_id'] ?? '')),
            'output_schema_version' => sanitize_text_field((string) ($input['output_schema_version'] ?? '')),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'status' => sanitize_key((string) ($input['status'] ?? 'draft')),
            'checksum_sha256' => $checksum,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ], 'ai_prompt_version');
    }

    public function createRun(array $input, ?string $key = null): array|\WP_Error
    {
        $taskId = absint($input['task_id'] ?? 0);
        $promptVersionId = absint($input['prompt_version_id'] ?? 0);
        $task = $this->find(Tables::ai_tasks(), $taskId);
        $prompt = $this->find(Tables::ai_prompt_versions(), $promptVersionId);
        if ($task === null || $prompt === null) {
            return $this->error('invalid_relationship', 'Valid task_id and prompt_version_id are required.');
        }
        $payload = $input['input_payload'] ?? [];
        if (! is_array($payload)) {
            return $this->error('validation', 'input_payload must be an object.');
        }
        $schema = is_array($prompt['input_schema'] ?? null) ? $prompt['input_schema'] : [];
        $validation = (new SchemaValidator())->validate($payload, $schema);
        if (! $validation['valid']) {
            Logger::audit('ai_run_validation_failed', ['errors' => $validation['errors']], 'ai_run', '0');
            return $this->error('schema_validation', 'Input failed schema validation.', 422);
        }
        $models = $this->modelsForEnvironment((string) $task['environment']);
        $decision = (new RoutingPolicy())->choose($task, $models);
        if (is_wp_error($decision)) {
            return $decision;
        }
        $routing = $this->encode($decision);
        $encodedPayload = $this->encode($payload);
        $provenance = $this->encode($input['provenance'] ?? []);
        foreach ([$routing, $encodedPayload, $provenance] as $encoded) {
            if (is_wp_error($encoded)) {
                return $encoded;
            }
        }
        return $this->insert(Tables::ai_runs(), $key, [
            'task_id' => $taskId,
            'model_id' => (int) $decision['model_id'],
            'prompt_version_id' => $promptVersionId,
            'environment' => (string) $decision['environment'],
            'routing_decision' => $routing,
            'input_schema_id' => (string) ($prompt['input_schema_id'] ?? ''),
            'input_schema_version' => (string) ($prompt['input_schema_version'] ?? ''),
            'output_schema_id' => (string) ($prompt['output_schema_id'] ?? ''),
            'output_schema_version' => (string) ($prompt['output_schema_version'] ?? ''),
            'input_payload' => $encodedPayload,
            'input_fingerprint' => hash('sha256', $encodedPayload),
            'state' => Lifecycle::DRAFT,
            'provenance' => $provenance,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], 'ai_run');
    }

    public function transitionRun(int $id, string $to): array|\WP_Error
    {
        $to = strtoupper(sanitize_key($to));
        if (Lifecycle::executionState($to) || ! Lifecycle::isAllowed($to)) {
            return $this->error('execution_disabled', 'AI execution states are unavailable in Batch 5.', 409);
        }
        $run = $this->find(Tables::ai_runs(), $id);
        if ($run === null) {
            return $this->error('not_found', 'AI run not found.', 404);
        }
        if (! Lifecycle::canTransition((string) $run['state'], $to)) {
            return $this->error('invalid_transition', 'Invalid AI run state transition.', 409);
        }
        if ($to === Lifecycle::APPROVED_FOR_EXECUTION && ! $this->hasApproval($id, 'run')) {
            return $this->error('review_required', 'Human approval is required.', 409);
        }
        global $wpdb;
        $ok = $wpdb->update(
            Tables::ai_runs(),
            ['state' => $to, 'updated_at' => current_time('mysql', true)],
            ['id' => $id, 'state' => (string) $run['state']],
            ['%s', '%s'],
            ['%d', '%s']
        );
        if ($ok !== 1) {
            return $this->error('conflict', 'AI run changed concurrently.', 409);
        }
        Logger::audit('ai_run_transitioned', ['from' => $run['state'], 'to' => $to], 'ai_run', (string) $id);
        return $this->find(Tables::ai_runs(), $id) ?? $this->error('not_found', 'AI run not found.', 404);
    }

    public function storeOutput(int $runId, array $input, ?string $key = null): array|\WP_Error
    {
        $run = $this->find(Tables::ai_runs(), $runId);
        if ($run === null) {
            return $this->error('not_found', 'AI run not found.', 404);
        }
        $payload = $input['payload'] ?? [];
        if (! is_array($payload)) {
            return $this->error('validation', 'payload must be structured.');
        }
        $prompt = $this->find(Tables::ai_prompt_versions(), (int) $run['prompt_version_id']);
        if ($prompt === null) {
            return $this->error('invalid_relationship', 'Prompt version missing.', 409);
        }
        $validation = (new SchemaValidator())->validate($payload, is_array($prompt['output_schema'] ?? null) ? $prompt['output_schema'] : []);
        if (! $validation['valid']) {
            return $this->error('schema_validation', 'Output failed schema validation.', 422);
        }
        $encoded = $this->encode($payload);
        if (is_wp_error($encoded)) {
            return $encoded;
        }
        return $this->insert(Tables::ai_outputs(), $key, [
            'run_id' => $runId,
            'task_id' => (int) $run['task_id'],
            'model_id' => (int) $run['model_id'],
            'prompt_version_id' => (int) $run['prompt_version_id'],
            'schema_id' => (string) $run['output_schema_id'],
            'schema_version' => (string) $run['output_schema_version'],
            'payload' => $encoded,
            'source_input_fingerprint' => (string) $run['input_fingerprint'],
            'review_state' => 'UNREVIEWED',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ], 'ai_output');
    }

    public function recordUsage(int $runId, array $input, ?string $key = null): array|\WP_Error
    {
        $run = $this->find(Tables::ai_runs(), $runId);
        if ($run === null) {
            return $this->error('not_found', 'AI run not found.', 404);
        }
        $model = $this->find(Tables::ai_models(), (int) $run['model_id']);
        if ($model === null) {
            return $this->error('invalid_relationship', 'Model missing.', 409);
        }
        return $this->insert(Tables::ai_usage(), $key, [
            'run_id' => $runId,
            'provider' => (string) $model['provider'],
            'model_key' => (string) $model['model_key'],
            'environment' => (string) $run['environment'],
            'metering_unit' => sanitize_key((string) ($input['metering_unit'] ?? 'tokens')),
            'request_units' => max(0, (int) ($input['request_units'] ?? 1)),
            'input_units' => max(0, (int) ($input['input_units'] ?? 0)),
            'output_units' => max(0, (int) ($input['output_units'] ?? 0)),
            'estimated_cost' => max(0, (float) ($input['estimated_cost'] ?? 0)),
            'currency' => strtoupper(substr(sanitize_text_field((string) ($input['currency'] ?? 'USD')), 0, 3)),
            'estimate_source' => sanitize_text_field((string) ($input['estimate_source'] ?? 'manual')),
            'estimate_version' => sanitize_text_field((string) ($input['estimate_version'] ?? 'v1')),
            'created_at' => current_time('mysql', true),
        ], 'ai_usage');
    }

    public function review(int $runId, array $input, ?string $key = null): array|\WP_Error
    {
        $run = $this->find(Tables::ai_runs(), $runId);
        if ($run === null) {
            return $this->error('not_found', 'AI run not found.', 404);
        }
        $decision = strtoupper(sanitize_key((string) ($input['decision'] ?? '')));
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            return $this->error('validation', 'Decision must be APPROVED or REJECTED.');
        }
        $targetType = sanitize_key((string) ($input['target_type'] ?? 'run'));
        if (! in_array($targetType, ['run', 'output'], true)) {
            return $this->error('validation', 'Invalid review target.');
        }
        $targetId = $targetType === 'run' ? $runId : absint($input['target_id'] ?? 0);
        if ($targetType === 'output' && $this->find(Tables::ai_outputs(), $targetId) === null) {
            return $this->error('not_found', 'AI output not found.', 404);
        }
        return $this->insert(Tables::ai_reviews(), $key, [
            'run_id' => $runId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'decision' => $decision,
            'notes' => sanitize_textarea_field((string) ($input['notes'] ?? '')),
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time('mysql', true),
        ], 'ai_review');
    }

    public function list(string $entity, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE): array
    {
        $map = [
            'tasks' => Tables::ai_tasks(), 'models' => Tables::ai_models(), 'prompts' => Tables::ai_prompts(),
            'prompt-versions' => Tables::ai_prompt_versions(), 'runs' => Tables::ai_runs(), 'outputs' => Tables::ai_outputs(),
            'usage' => Tables::ai_usage(), 'reviews' => Tables::ai_reviews(),
        ];
        if (! isset($map[$entity])) {
            return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => $perPage, 'total_items' => 0, 'total_pages' => 0]];
        }
        $page = max(1, $page);
        $perPage = min(self::MAX_PAGE_SIZE, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        global $wpdb;
        $table = $map[$entity];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d', $perPage, $offset), ARRAY_A);
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        return ['items' => array_map([$this, 'normalize'], is_array($rows) ? $rows : []), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $total, 'total_pages' => (int) ceil($total / $perPage)]];
    }

    public function find(string $table, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function normalize(array $row): array
    {
        foreach (['id','task_id','model_id','prompt_id','prompt_version_id','run_id','target_id','created_by','reviewed_by','request_units','input_units','output_units','quality_tier','latency_ms','fallback_order'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }
        foreach (['cost_per_1k','estimated_cost'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (float) $row[$field];
            }
        }
        foreach (['enabled','prohibited'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (bool) $row[$field];
            }
        }
        unset($row['idempotency_key']);
        foreach (['required_capabilities','capabilities','input_schema','output_schema','routing_decision','input_payload','provenance','payload'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    private function modelsForEnvironment(string $environment): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Tables::ai_models() . ' WHERE environment=%s AND enabled=1', $environment), ARRAY_A);
        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    private function hasApproval(int $runId, string $targetType): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Tables::ai_reviews() . ' WHERE run_id=%d AND target_type=%s AND decision=%s',
            $runId,
            $targetType,
            'APPROVED'
        )) > 0;
    }

    private function insert(string $table, ?string $key, array $data, string $type): array|\WP_Error
    {
        $key = $this->idempotencyKey($key);
        if (is_wp_error($key)) {
            return $key;
        }
        global $wpdb;
        if ($key !== null) {
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE idempotency_key=%s', $key), ARRAY_A);
            if (is_array($existing)) {
                return $this->normalize($existing) + ['idempotent_replay' => true];
            }
            $data['idempotency_key'] = $key;
        }
        if (! $wpdb->insert($table, $data)) {
            return $this->error('create_failed', 'Unable to create AI governance record.', 500);
        }
        $id = (int) $wpdb->insert_id;
        Logger::audit($type . '_created', ['idempotency_key' => $key === null ? '' : '[PRESENT]'], $type, (string) $id);
        return $this->find($table, $id) ?? $this->error('create_failed', 'Unable to read AI governance record.', 500);
    }

    private function idempotencyKey(?string $key): string|null|\WP_Error
    {
        $key = $key === null ? '' : trim(sanitize_text_field($key));
        if ($key === '') {
            return null;
        }
        if (strlen($key) > 191) {
            return $this->error('validation', 'Idempotency key too long.');
        }
        return $key;
    }

    private function encode(mixed $value): string|\WP_Error
    {
        if (! is_array($value)) {
            return $this->error('validation', 'Structured value must be an array/object.');
        }
        if ($this->containsCredentialKey($value)) {
            return $this->error('credential_key', 'Credential-like keys are not allowed in AI payloads/configuration.', 400);
        }
        $encoded = wp_json_encode($value);
        return is_string($encoded) ? $encoded : $this->error('encoding_failed', 'Unable to encode structured AI data.', 400);
    }

    private function containsCredentialKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && Logger::isCredentialKey($key)) {
                return true;
            }
            if (is_array($item) && $this->containsCredentialKey($item)) {
                return true;
            }
        }
        return false;
    }

    private function environment(mixed $value): string
    {
        $environment = sanitize_key((string) $value);
        return in_array($environment, ['sandbox', 'test', 'production'], true) ? $environment : 'sandbox';
    }

    private function error(string $code, string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error('digiforge_ai_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
