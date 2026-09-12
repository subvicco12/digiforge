<?php

declare(strict_types=1);

namespace DigiForge\Queue;

use DigiForge\Database\Tables;

/** Persists governed job intent. Worker execution remains deliberately absent. */
final class JobRepository
{
    public function enqueue(string $type, array $payload = [], string $idempotencyKey = ''): int|false
    {
        global $wpdb;
        $idempotencyKey = sanitize_text_field($idempotencyKey);
        if ($idempotencyKey !== '') {
            $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::jobs() . ' WHERE idempotency_key = %s', $idempotencyKey));
            if ($found) {
                return (int) $found;
            }
        }

        $now = current_time('mysql', true);
        $data = [
            'job_type' => sanitize_key($type),
            'state' => 'BLOCKED',
            'payload' => wp_json_encode($payload),
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
            $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::jobs() . ' WHERE idempotency_key = %s', $idempotencyKey));
            if ($found) {
                return (int) $found;
            }
        }

        return false;
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

    public function scheduleRetry(int $id, int $delaySeconds, string $error): bool
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT attempts, max_attempts FROM ' . Tables::jobs() . " WHERE id = %d AND state = 'RUNNING'",
                $id
            ),
            ARRAY_A
        );
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
