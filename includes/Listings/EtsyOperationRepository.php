<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;
use WP_Error;

/**
 * Persistence boundary for Etsy operation records.
 * This repository is local-only: it never contacts Etsy or performs an external action.
 */
final class EtsyOperationRepository
{
    /**
     * Creates an operation from the actual request payload using the shared
     * canonical fingerprint contract. Callers cannot supply a conflicting
     * request fingerprint through this boundary.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function createFromPayload(array $input, array $payload): array|WP_Error
    {
        $fingerprint = EtsyRequestFingerprint::fromPayload($payload);
        if ($fingerprint instanceof WP_Error) return $fingerprint;

        if (isset($input['request_fingerprint'])) {
            $supplied = strtolower(trim((string)$input['request_fingerprint']));
            if ($supplied !== '' && !hash_equals($fingerprint, $supplied)) {
                return $this->error('request_fingerprint_conflict', 'Supplied request fingerprint does not match the canonical payload fingerprint.', 409);
            }
        }

        $input['request_fingerprint'] = $fingerprint;
        return $this->create($input);
    }

    /**
     * Legacy/precomputed creation boundary retained for compatibility.
     * New payload-backed creation should use createFromPayload().
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create(array $input): array|WP_Error
    {
        $record = EtsyOperationRecord::canonicalize($input);
        if ($record instanceof WP_Error) return $record;

        $scope = $this->approvedScope((int)$record['intent_id'], (int)$record['draft_package_id'], (string)$record['shop_reference']);
        if ($scope instanceof WP_Error) return $scope;

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
        $data = $record + ['created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now];
        if ($wpdb->insert($table, $data) !== 1) {
            $existing = $this->byKey((string)$record['shop_reference'], (string)$record['idempotency_key']);
            if (is_array($existing) && $this->sameRequest($existing, $record)) return $existing + ['idempotent_replay' => true];
            return $this->error('create_failed', 'Unable to persist Etsy operation record.', 500);
        }
        $id=(int)$wpdb->insert_id;
        Logger::audit('etsy_operation_created', ['intent_id'=>(int)$record['intent_id'],'draft_package_id'=>(int)$record['draft_package_id']], 'etsy_operation', (string)$id);
        return $this->find($id) ?? $this->error('create_failed', 'Unable to reload Etsy operation record.', 500);
    }

    /** @return array<string,mixed>|WP_Error */
    public function transition(int $id, string $to, string $externalReference = ''): array|WP_Error
    {
        $row = $this->find($id);
        if (!is_array($row)) return $this->error('not_found', 'Etsy operation record not found.', 404);

        $to = strtoupper(trim($to));
        $externalReference = trim($externalReference);
        if (strlen($externalReference) > 191) return $this->error('invalid_external_reference', 'External reference is too long.', 400);
        if ($to === EtsyOperationLifecycle::CONFIRMED_SUCCESS && $externalReference === '') {
            return $this->error('external_reference_required', 'Confirmed success requires an external reference.', 409);
        }

        $from = (string)$row['state'];
        if ($from === $to) {
            if ($to === EtsyOperationLifecycle::CONFIRMED_SUCCESS && !hash_equals((string)$row['external_reference'], $externalReference)) {
                return $this->error('external_reference_conflict', 'Confirmed success already has a different external reference.', 409);
            }
            return $row + ['idempotent_transition' => true];
        }
        if (!in_array($to, EtsyOperationLifecycle::states(), true) || !EtsyOperationLifecycle::canTransition($from, $to)) {
            return $this->error('invalid_transition', 'Etsy operation lifecycle transition is not permitted.', 409);
        }

        global $wpdb;
        $data = ['state' => $to, 'updated_at' => current_time('mysql', true)];
        if ($externalReference !== '') $data['external_reference'] = $externalReference;
        $updated = $wpdb->update($wpdb->prefix . 'digiforge_etsy_operations', $data, ['id' => $id, 'state' => $from]);
        if ($updated !== 1) return $this->error('transition_conflict', 'Etsy operation state changed concurrently or update failed.', 409);

        Logger::audit('etsy_operation_state_changed', [
            'actor_id'=>get_current_user_id(),
            'from'=>$from,
            'to'=>$to,
            'external_reference_present'=>$externalReference !== '',
        ], 'etsy_operation', (string)$id);
        return $this->find($id) ?? $this->error('not_found', 'Etsy operation record not found after transition.', 500);
    }

    /** @return array<string,mixed>|WP_Error */
    private function approvedScope(int $intentId, int $packageId, string $shopReference): array|WP_Error
    {
        global $wpdb;
        $intent=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_intents().' WHERE id=%d LIMIT 1',$intentId),ARRAY_A);
        $package=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::etsy_draft_packages().' WHERE id=%d LIMIT 1',$packageId),ARRAY_A);
        if (!is_array($intent) || (string)($intent['state']??'') !== 'APPROVED_INTENT') return $this->error('intent_not_approved','Etsy operation requires an approved intent.',409);
        if (!is_array($package) || (int)($package['approved_by']??0) < 1 || empty($package['approved_at'])) return $this->error('package_not_approved','Etsy operation requires an approved draft package.',409);
        if ((int)($intent['draft_package_id']??0) !== $packageId || (int)($intent['listing_id']??0) !== (int)($package['listing_id']??0)) {
            return $this->error('scope_mismatch','Intent and draft package must belong to the same listing scope.',409);
        }
        $listing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::listings().' WHERE id=%d LIMIT 1',(int)$intent['listing_id']),ARRAY_A);
        if (!is_array($listing) || !hash_equals((string)($listing['shop_reference']??''),$shopReference)) {
            return $this->error('shop_scope_mismatch','Operation shop reference must match the approved listing scope.',409);
        }
        return ['intent'=>$intent,'package'=>$package,'listing'=>$listing];
    }

    /** Persist bounded provider identity evidence learned from an attempted Etsy response. */
    public function recordReconciliationReference(int $id,string $reference): array|WP_Error
    {
        $reference=trim($reference);
        if ($id<1 || $reference==='' || strlen($reference)>191 || !preg_match('/^[A-Za-z0-9._:-]+$/',$reference)) {
            return $this->error('reconciliation_reference','Invalid Etsy reconciliation reference.',400);
        }
        $row=$this->find($id);
        if (!is_array($row)) return $this->error('not_found','Etsy operation record not found.',404);
        $state=(string)($row['state']??'');
        if (!in_array($state,[EtsyOperationLifecycle::SENT,EtsyOperationLifecycle::UNKNOWN,EtsyOperationLifecycle::RECONCILIATION,EtsyOperationLifecycle::RECONCILED],true)) {
            return $this->error('reconciliation_reference_state','Reconciliation identity may only be recorded after an Etsy attempt or during uncertainty resolution.',409);
        }
        $existing=trim((string)($row['reconciliation_reference']??''));
        if ($existing!=='') {
            if (!hash_equals($existing,$reference)) return $this->error('reconciliation_reference_conflict','Etsy reconciliation reference already differs.',409);
            return $row+['idempotent_reconciliation_reference'=>true];
        }
        global $wpdb;
        $updated=$wpdb->update($wpdb->prefix.'digiforge_etsy_operations',[
            'reconciliation_reference'=>$reference,
            'updated_at'=>current_time('mysql',true),
        ],['id'=>$id,'reconciliation_reference'=>'']);
        if ($updated!==1) {
            $current=$this->find($id);
            $persisted=is_array($current)?trim((string)($current['reconciliation_reference']??'')):'';
            if ($persisted!=='' && hash_equals($persisted,$reference)) return $current+['idempotent_reconciliation_reference'=>true];
            return $this->error('reconciliation_reference_conflict','Unable to atomically persist Etsy reconciliation reference.',409);
        }
        return $this->find($id)??$this->error('not_found','Etsy operation record not found after identity update.',500);
    }

    /** Persist a bounded non-secret canonical request snapshot for operation-specific reconciliation. */
    public function recordReconciliationEvidence(int $id,array $payload): array|WP_Error
    {
        if($id<1)return $this->error('reconciliation_evidence','Invalid Etsy operation id.',400);
        $fingerprint=EtsyRequestFingerprint::fromPayload($payload);
        if($fingerprint instanceof WP_Error)return $fingerprint;
        $row=$this->find($id);
        if(!is_array($row))return $this->error('not_found','Etsy operation record not found.',404);
        if(!hash_equals((string)($row['request_fingerprint']??''),$fingerprint))return $this->error('reconciliation_evidence_fingerprint','Reconciliation evidence must match the authorized request fingerprint.',409);
        $encoded=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($encoded)||strlen($encoded)>65535)return $this->error('reconciliation_evidence_size','Reconciliation evidence exceeds the bounded storage limit.',400);
        $existing=(string)($row['reconciliation_evidence']??'');
        if($existing!==''){
            if(!hash_equals(hash('sha256',$existing),hash('sha256',$encoded)))return $this->error('reconciliation_evidence_conflict','Reconciliation evidence is immutable once recorded.',409);
            return $row+['idempotent_reconciliation_evidence'=>true];
        }
        global $wpdb;
        $updated=$wpdb->update($wpdb->prefix.'digiforge_etsy_operations',['reconciliation_evidence'=>$encoded,'updated_at'=>current_time('mysql',true)],['id'=>$id,'reconciliation_evidence'=>null]);
        if($updated!==1)return $this->error('reconciliation_evidence_conflict','Unable to atomically persist reconciliation evidence.',409);
        return $this->find($id)??$this->error('not_found','Etsy operation record not found after evidence update.',500);
    }

    /** Acquire a connection-scoped mutex so the same persisted operation cannot execute concurrently. */
    public function acquireExecutionLock(int $id): bool
    {
        if ($id < 1) return false;
        global $wpdb;
        $key='digiforge_etsy_operation_'.$id;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))===1;
    }

    public function releaseExecutionLock(int $id): void
    {
        if ($id < 1) return;
        global $wpdb;
        $key='digiforge_etsy_operation_'.$id;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'digiforge_etsy_operations WHERE id=%d LIMIT 1',$id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function byKey(string $shopReference, string $idempotencyKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'digiforge_etsy_operations WHERE shop_reference=%s AND idempotency_key=%s LIMIT 1',$shopReference,$idempotencyKey), ARRAY_A);
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
