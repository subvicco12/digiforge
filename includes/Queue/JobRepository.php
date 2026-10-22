<?php

declare(strict_types=1);

namespace DigiForge\Queue;

use DigiForge\Database\Tables;
use WP_Error;

/** Persists governed job intent. Worker execution remains deliberately absent. */
final class JobRepository
{
    public function enqueue(string $type, array $payload = [], string $idempotencyKey = '', array $dependencies = []): int|false|WP_Error
    {
        global $wpdb;
        if (array_key_exists('_digiforge_dependencies', $payload) || count($dependencies) > 100 || count(array_unique($dependencies, SORT_REGULAR)) !== count($dependencies)) {
            return new WP_Error('job_dependencies_invalid', 'Bounded distinct dependency identities must use the explicit dependency argument.');
        }
        foreach ($dependencies as $dependency) if (!is_int($dependency) || $dependency < 1) return new WP_Error('job_dependencies_invalid', 'Positive persisted dependency IDs are required.');
        if ($dependencies !== []) $payload['_digiforge_dependencies'] = $dependencies;
        $type = sanitize_key($type);
        $encoded = wp_json_encode($payload);
        if ($type === '' || !is_string($encoded)) return new WP_Error('job_intent_invalid', 'Valid job type and JSON payload are required.');
        $idempotencyKey = sanitize_text_field($idempotencyKey);
        if ($idempotencyKey !== '') {
            $wpdb->last_error='';$found = $wpdb->get_row($wpdb->prepare('SELECT id,job_type,payload FROM ' . Tables::jobs() . ' WHERE idempotency_key = %s', $idempotencyKey), ARRAY_A);
            if(!empty($wpdb->last_error))return new WP_Error('job_idempotency_evidence_unavailable','Job idempotency evidence is unavailable; enqueue is blocked.',['status'=>503]);
            if ($found) {
                return $this->replayIdentity($found, $type, $encoded);
            }
        }

        $now = current_time('mysql', true);
        $data = [
            'job_type' => $type,
            'state' => 'BLOCKED',
            'payload' => $encoded,
            'max_attempts' => 3,
            'created_at' => $now,
            'updated_at' => $now,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
        ];
        $ok = $wpdb->insert(Tables::jobs(), $data, ['%s', '%s', '%s', '%d', '%s', '%s', $idempotencyKey !== '' ? '%s' : null]);
        if ($ok) {
            return (int) $wpdb->insert_id;
        }

        if ($idempotencyKey !== '') {
            $wpdb->last_error='';            $found = $wpdb->get_row($wpdb->prepare('SELECT id,job_type,payload FROM ' . Tables::jobs() . ' WHERE idempotency_key = %s', $idempotencyKey), ARRAY_A);
            if(!empty($wpdb->last_error))return new WP_Error('job_idempotency_evidence_unavailable','Job idempotency reconciliation evidence is unavailable; do not retry automatically.',['status'=>503]);
            if ($found) {
                return $this->replayIdentity($found, $type, $encoded);
            }
        }

        return false;
    }

    private function replayIdentity(array $row, string $type, string $payload): int|WP_Error
    {
        if ((int)($row['id'] ?? 0) < 1 || ($row['job_type'] ?? null) !== $type || ($row['payload'] ?? null) !== $payload) {
            return new WP_Error('job_idempotency_conflict', 'This job identity already binds different persisted intent; no enqueue or replay is permitted.', ['status'=>409]);
        }
        return (int)$row['id'];
    }

    public function transition(int $id, string $from, string $to): bool
    {
        if ($to === 'RUNNING' || ! JobState::canTransition($from, $to)) {
            return false;
        }

        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Tables::jobs() . ' SET state = %s, updated_at = %s WHERE id = %d AND state = %s',
                $to,
                current_time('mysql', true),
                $id,
                $from
            )
        );

        return $updated === 1;
    }

    public function start(int $id, string $owner): bool
    {
        if (!$this->dependenciesReady($id)) return false;
        global $wpdb;
        $now = current_time('mysql', true);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Tables::jobs() . " SET state = 'RUNNING', updated_at = %s "
                . "WHERE id = %d AND state = 'QUEUED' AND locked_by = %s AND lease_expires_at >= %s",
                $now,
                $id,
                sanitize_key($owner),
                $now
            )
        );

        return $updated === 1;
    }

    public function acquireLease(int $id, string $owner, int $leaseSeconds = 60): bool
    {
        global $wpdb;
        $owner = sanitize_key($owner);
        if ($owner === '' || $leaseSeconds < 1 || $leaseSeconds > 3600) {
            return false;
        }
        if (!$this->dependenciesReady($id)) return false;

        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Tables::jobs() . ' SET locked_by = %s, locked_at = %s, lease_expires_at = %s, updated_at = %s '
                . "WHERE id = %d AND state = 'QUEUED' AND (lease_expires_at IS NULL OR lease_expires_at < %s)",
                $owner,
                $now,
                $expires,
                $now,
                $id,
                $now
            )
        );

        return $updated === 1;
    }

    private function dependenciesReady(int $id): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $payload = $wpdb->get_var($wpdb->prepare('SELECT payload FROM '.Tables::jobs().' WHERE id=%d', $id));
        if (!empty($wpdb->last_error) || !is_string($payload)) return false;
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) return false;
        $dependencies = array_key_exists('_digiforge_dependencies', $decoded) ? $decoded['_digiforge_dependencies'] : [];
        if (!is_array($dependencies) || count($dependencies) > 100) return false;
        $rows = [];
        foreach ($dependencies as $dependency) {
            if (!is_int($dependency) || $dependency < 1) return false;
            $wpdb->last_error = '';
            $row = $wpdb->get_row($wpdb->prepare('SELECT id,state FROM '.Tables::jobs().' WHERE id=%d', $dependency), ARRAY_A);
            if (!empty($wpdb->last_error) || !is_array($row)) return false;
            $rows[$dependency] = $row;
        }
        return QueueDependencyPolicy::evaluate($id, $dependencies, $rows)['dependencies_ready'];
    }

    /** Persist a local retry plan only; never dispatch or grant provider authority. */
    public function scheduleClassifiedRetry(int $id, int $delaySeconds, string $errorCode, array $errorData): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare('SELECT attempts,max_attempts FROM '.Tables::jobs()." WHERE id=%d AND state='RUNNING'", $id), ARRAY_A);
        if (!empty($wpdb->last_error) || !is_array($row)) return false;
        $policy = QueueFailurePolicy::evaluate($errorCode, $errorData, (int)$row['attempts'], (int)$row['max_attempts'], $delaySeconds);
        if (!in_array($policy['state'], ['RETRY','DEAD_LETTER'], true)) return false;
        return $this->persistRetry($id, $delaySeconds, $errorCode);
    }

    public function releaseLease(int $id, string $owner): bool
    {
        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Tables::jobs() . ' SET locked_by = NULL, locked_at = NULL, lease_expires_at = NULL, updated_at = %s '
                . 'WHERE id = %d AND locked_by = %s',
                current_time('mysql', true),
                $id,
                sanitize_key($owner)
            )
        );

        return $updated === 1;
    }

    public function scheduleRetry(int $id, int $delaySeconds, string $error, array $errorData = []): bool
    {
        return $this->scheduleClassifiedRetry($id, $delaySeconds, $error, $errorData);
    }

    private function persistRetry(int $id, int $delaySeconds, string $error): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT attempts, max_attempts FROM ' . Tables::jobs() . " WHERE id = %d AND state = 'RUNNING'",
                $id
            ),
            ARRAY_A
        );
        if (! empty($wpdb->last_error)) {
            return false;
        }
        if (! is_array($row)) {
            return false;
        }

        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, (int) $row['max_attempts']);
        $nextAttempts = $attempts + 1;
        $now = current_time('mysql', true);

        if ($nextAttempts >= $maxAttempts) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . Tables::jobs() . " SET state = 'DEAD_LETTER', attempts = %d, last_error = %s, "
                    . 'dead_lettered_at = %s, locked_by = NULL, locked_at = NULL, lease_expires_at = NULL, '
                    . "updated_at = %s WHERE id = %d AND state = 'RUNNING' AND attempts = %d",
                    $nextAttempts,
                    sanitize_text_field($error),
                    $now,
                    $now,
                    $id,
                    $attempts
                )
            );

            return $updated === 1;
        }

        $nextAttempt = gmdate('Y-m-d H:i:s', time() + max(1, min($delaySeconds, 86400)));
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Tables::jobs() . " SET state = 'RETRY', attempts = %d, next_attempt_at = %s, "
                . 'last_error = %s, locked_by = NULL, locked_at = NULL, lease_expires_at = NULL, updated_at = %s '
                . "WHERE id = %d AND state = 'RUNNING' AND attempts = %d",
                $nextAttempts,
                $nextAttempt,
                sanitize_text_field($error),
                $now,
                $id,
                $attempts
            )
        );

        return $updated === 1;
    }

    public function recoverExpiredLease(int $id): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Tables::jobs() . " SET state = 'HUMAN_REVIEW', last_error = 'LEASE_EXPIRED', "
                . 'locked_by = NULL, locked_at = NULL, lease_expires_at = NULL, updated_at = %s '
                . "WHERE id = %d AND state = 'RUNNING' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
                $now,
                $id,
                $now
            )
        );

        return $updated === 1;
    }

    public function deadLetter(int $id, string $error): bool
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Tables::jobs() . " SET state = 'DEAD_LETTER', last_error = %s, dead_lettered_at = %s, "
                . 'locked_by = NULL, locked_at = NULL, lease_expires_at = NULL, updated_at = %s '
                . "WHERE id = %d AND state IN ('RUNNING','RETRY')",
                sanitize_text_field($error),
                $now,
                $now,
                $id
            )
        );

        return $updated === 1;
    }
}
