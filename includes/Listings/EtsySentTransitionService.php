<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Sole local boundary for persisting NOT_SENT -> SENT after validated evidence
 * proves an external request was actually attempted.
 */
final class EtsySentTransitionService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function record(array $evidence): array|WP_Error
    {
        if (($evidence['state'] ?? '') !== 'ETSY_EXTERNAL_ATTEMPT_EVIDENCED'
            || ($evidence['sent_transition_eligible'] ?? null) !== true
            || ($evidence['sent_transition_performed'] ?? null) !== false
            || ($evidence['external_execution_performed'] ?? null) !== true) {
            return $this->error('invalid_evidence','Validated external-attempt evidence is required.');
        }

        $operationId=(int)($evidence['operation_id'] ?? 0);
        $attemptId=trim((string)($evidence['attempt_id'] ?? ''));
        $attemptedAt=trim((string)($evidence['attempted_at'] ?? ''));
        if ($operationId < 1 || $attemptId === '' || strlen($attemptId) > 191 || $attemptedAt === '') {
            return $this->error('invalid_attempt','Valid attempt identity and timestamp are required.');
        }

        $operation=$this->operations->find($operationId);
        if (!is_array($operation) || (string)($operation['state'] ?? '') !== EtsyOperationLifecycle::NOT_SENT) {
            return $this->error('operation_not_sendable','Only a NOT_SENT operation may be marked SENT.');
        }

        $transitioned=$this->operations->transition($operationId,EtsyOperationLifecycle::SENT);
        if ($transitioned instanceof WP_Error) return $transitioned;

        return [
            'state'=>'ETSY_SENT_RECORDED',
            'operation'=>$transitioned,
            'operation_id'=>$operationId,
            'attempt_id'=>$attemptId,
            'attempted_at'=>$attemptedAt,
            'sent_transition_performed'=>true,
            'external_execution_performed'=>true,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_sent_transition_'.$code,$message,['status'=>409]);
    }
}
