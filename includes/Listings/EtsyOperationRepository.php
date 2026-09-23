<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Persistence boundary for Etsy operation records.
 * This repository is local-only: it never contacts Etsy or performs an external action.
 */
final class EtsyOperationRepository
{
    /** @return array<string,mixed>|WP_Error */
    public function create(array $input): array|WP_Error
    {
        $record = EtsyOperationRecord::canonicalize($input);
        if ($record instanceof WP_Error) return $record;

        global $wpdb;
        $table = $wpdb->prefix . 'digiforge_etsy_operations';
        $existing = $this->byKey((string)$record['shop_reference'], (string)$record['idempotency_key']);
        if (is_array($existing)) {
            if (!$this->sameRequest($existing, $record)) {
                return $this->error('idempotency_conflict', 'Idempotency key already belongs to a different Etsy operation.', 409);
            }
            return $existing + ['idempotent_replay' => true];
        }

        $now = current_time('mysql', true);
        $data = $record + [
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($wpdb->insert($table, $data) !== 1) {
            $existing = $this->byKey((string)$record['shop_reference'], (string)$record['idempotency_key']);
            if (is_array($existing) && $this->sameRequest($existing, $record)) {
                return $existing + ['idempotent_replay' => true];
            }
            return $this->error('create_failed', 'Unable to persist Etsy operation record.', 500);
        }
        return $this->find((int)$wpdb->insert_id) ?? $this->error('create_failed', 'Unable to reload Etsy operation record.', 500);
    }

    /** @return array<string,mixed>|WP_Error */
    public function transition(int $id, string $to, string $externalReference = ''): array|WP_Error
    {
        $row = $this->find($id);
        if (!is_array($row)) return $this->error('not_found', 'Etsy operation record not found.', 404);

        $to = strtoupper(trim($to));
        $from = (string)$row['state'];
        if ($from === $to) return $row + ['idempotent_transition' => true];
        if (!in_array($to, EtsyOperationLifecycle::states(), true) || !EtsyOperationLifecycle::canTransition($from, $to)) {
            return $this->error('invalid_transition', 'Etsy operation lifecycle transition is not permitted.', 409);
        }

        $externalReference = trim($externalReference);
        if (strlen($externalReference) > 191) {
            return $this->error('invalid_external_reference', 'External reference is too long.', 400);
        }
        if ($to === EtsyOperationLifecycle::CONFIRMED_SUCCESS && $externalReference === '') {
            return $this->error('external_reference_required', 'Confirmed success requires an external reference.', 409);
        }

        global $wpdb;
        $data = ['state' => $to, 'updated_at' => current_time('mysql', true)];
        if ($externalReference !== '') $data['external_reference'] = $externalReference;
        $updated = $wpdb->update(
            $wpdb->prefix . 'digiforge_etsy_operations',
            $data,
            ['id' => $id, 'state' => $from]
        );
        if ($updated !== 1) return $this->error('transition_conflict', 'Etsy operation state changed concurrently or update failed.', 409);
        return $this->find($id) ?? $this->error('not_found', 'Etsy operation record not found after transition.', 500);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'digiforge_etsy_operations WHERE id=%d LIMIT 1',
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function byKey(string $shopReference, string $idempotencyKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'digiforge_etsy_operations WHERE shop_reference=%s AND idempotency_key=%s LIMIT 1',
            $shopReference,
            $idempotencyKey
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function sameRequest(array $existing, array $incoming): bool
    {
        foreach (['intent_id','draft_package_id','operation_type','request_fingerprint','authorization_hash','evidence_hash'] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($incoming[$field] ?? '')) return false;
        }
        return true;
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('digiforge_etsy_operation_' . $code, $message, ['status' => $status]);
    }
}
