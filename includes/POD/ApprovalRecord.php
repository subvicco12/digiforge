<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/** Records a human decision over immutable readiness evidence; executes nothing. */
final class ApprovalRecord
{
    /** @return array<string,mixed>|WP_Error */
    public static function decide(array $readiness,string $decision,int $userId,string $note=''): array|WP_Error
    {
        if(($readiness['state']??'')!=='READY_FOR_HUMAN_APPROVAL'){
            return new WP_Error('digiforge_pod_not_approval_ready','Readiness evidence must be READY_FOR_HUMAN_APPROVAL.',['status'=>409]);
        }
        $hash=strtolower(trim((string)($readiness['evidence_hash']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$hash)){
            return new WP_Error('digiforge_pod_evidence_hash','A valid readiness evidence hash is required.',['status'=>400]);
        }
        if(($readiness['publishing_enabled']??null)!==false||($readiness['order_execution_enabled']??null)!==false){
            return new WP_Error('digiforge_pod_execution_not_locked','Approval input must remain execution locked.',['status'=>409]);
        }
        $decision=strtoupper(trim($decision));
        if(!in_array($decision,['APPROVE','REJECT'],true)){
            return new WP_Error('digiforge_pod_approval_decision','Decision must be APPROVE or REJECT.',['status'=>400]);
        }
        if($userId<1) return new WP_Error('digiforge_pod_approver','A valid human approver is required.',['status'=>403]);

        return [
            'state'=>$decision==='APPROVE'?'HUMAN_APPROVED':'HUMAN_REJECTED',
            'decision'=>$decision,
            'evidence_hash'=>$hash,
            'approved_by'=>$userId,
            'note'=>sanitize_textarea_field($note),
            'decided_at'=>gmdate('c'),
            // Approval is evidence, not authority to execute external side effects.
            'publishing_enabled'=>false,
            'order_execution_enabled'=>false,
        ];
    }
}
