<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Security\ReviewedLocalEvidence;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Versioned independently reviewed local explicit allocations. No financial or execution authority. */
final class AllocationReview
{
    /** Reversals require an exact retained independently approved ordinary review. */
    public const PERSISTENT_REVERSALS_SUPPORTED=true;

    private const KIND='finance_allocation';

    public function propose(string $sourceHash,array $scope,array $explicitLines,int $effectiveAt,int $expiresAt,string $requestId,int $expectedRevision,?array $originalReviewReference=null): array|WP_Error
    {
        $scope=$this->admit($sourceHash,$scope);if ($scope instanceof WP_Error) return $scope;
        $original=null;
        if ($originalReviewReference!==null) {
            $original=$this->originalProof($originalReviewReference,$scope);
            if ($original instanceof WP_Error) return $original;
        }
        $packet=(new AllocationReadModel())->preview($sourceHash,$scope,$explicitLines,$original['packet']??null);
        if ($packet instanceof WP_Error) return self::error('source_unavailable',409);
        try { $payload=AllocationReviewPayload::create($sourceHash,$scope,$packet,$originalReviewReference); }
        catch (\InvalidArgumentException|\TypeError) { return self::error('invalid_payload',400); }
        $condition=fn(): bool|WP_Error => $this->validate($payload,$sourceHash,$scope) instanceof WP_Error ? self::error('changed',409) : true;
        $state=ReviewedLocalEvidence::propose(self::KIND,$scope['shop_key'],self::key($sourceHash,$scope),$payload,$effectiveAt,$expiresAt,$requestId,$expectedRevision,$condition);
        return $state instanceof WP_Error ? self::denied($state) : $this->readRequired($sourceHash,$scope);
    }

    /** Only APPROVED admits a reviewed local projection; rejection/revocation withdraw without requiring live source evidence. */
    public function decide(string $sourceHash,array $scope,int $expectedRevision,string $proposalHash,string $decision,string $requestId): array|WP_Error
    {
        $scope=$this->admit($sourceHash,$scope,true);if ($scope instanceof WP_Error) return $scope;
        $key=self::key($sourceHash,$scope);$state=ReviewedLocalEvidence::read(self::KIND,$scope['shop_key'],$key);
        if ($state instanceof WP_Error) return self::denied($state);
        if ($state===null || !is_array($state['payload']??null)) return self::error('missing',409);
        try { AllocationReviewPayload::packet($state['payload'],$sourceHash,$scope); }
        catch (\InvalidArgumentException|\TypeError) { return self::error('invalid_payload',409); }
        if ($decision==='APPROVED') {
            $valid=$this->validate($state['payload'],$sourceHash,$scope);if ($valid instanceof WP_Error) return $valid;
        }
        $condition=function() use ($sourceHash,$scope,$key,$state,$decision): bool|WP_Error {
            $again=ReviewedLocalEvidence::read(self::KIND,$scope['shop_key'],$key);
            if ($again!==$state) return self::error('changed',409);
            if ($decision==='APPROVED' && $this->validate($state['payload'],$sourceHash,$scope) instanceof WP_Error) return self::error('changed',409);
            return true;
        };
        $changed=ReviewedLocalEvidence::decide(self::KIND,$scope['shop_key'],$key,$expectedRevision,$proposalHash,$decision,$requestId,$condition);
        return $changed instanceof WP_Error ? self::denied($changed) : $this->readRequired($sourceHash,$scope);
    }

    /** Recovery-visible metadata; stale/noncurrent evidence cannot return a reviewed allocation packet. */
    public function read(string $sourceHash,array $scope): array|WP_Error|null
    {
        $scope=$this->admit($sourceHash,$scope);if ($scope instanceof WP_Error) return $scope;
        $key=self::key($sourceHash,$scope);$state=ReviewedLocalEvidence::read(self::KIND,$scope['shop_key'],$key);
        if ($state instanceof WP_Error) return self::denied($state);
        if ($state===null) return null;
        $packet=is_array($state['payload']??null) ? $this->validate($state['payload'],$sourceHash,$scope) : self::error('invalid_payload',409);
        $status=$state['status'];
        if (in_array($status,['PENDING','APPROVED'],true) && $state['expires_at']<=time()) $status='EXPIRED';
        if ($status==='PENDING') {
            foreach (['manage_digiforge','manage_digiforge_finance'] as $cap) {
                if (ShopAccess::authorize($scope['shop_key'],$cap,$state['author_id']) instanceof WP_Error) { $status='STALE';break; }
            }
        }
        if ($status==='APPROVED') {
            if ($state['effective_at']>time()) $status='NOT_YET_EFFECTIVE';
            elseif (ReviewedLocalEvidence::approved(self::KIND,$scope['shop_key'],$key) instanceof WP_Error) $status='STALE';
            else $status='REVIEWED_LOCAL_ALLOCATION';
        }
        if ($packet instanceof WP_Error && !in_array($status,['REJECTED','REVOKED','EXPIRED','NOT_YET_EFFECTIVE'],true)) $status='STALE';
        if (!($packet instanceof WP_Error) && isset($state['payload']['original_review_reference'])
            && in_array($status,['PENDING','REVIEWED_LOCAL_ALLOCATION'],true)
            && $this->validate($state['payload'],$sourceHash,$scope) instanceof WP_Error) return self::error('changed',409);
        if ($state!==ReviewedLocalEvidence::read(self::KIND,$scope['shop_key'],$key)) return self::error('changed',409);
        // A final actor/window recheck follows the complete source/target rebuild and stream reread.
        if ($status==='REVIEWED_LOCAL_ALLOCATION' && $state!==ReviewedLocalEvidence::approved(self::KIND,$scope['shop_key'],$key)) return self::error('changed',409);
        return self::project($sourceHash,$scope,$state,$status,
            !($packet instanceof WP_Error) && in_array($status,['PENDING','REVIEWED_LOCAL_ALLOCATION'],true) ? $packet : null);
    }

    public function current(string $sourceHash,array $scope): array|WP_Error
    {
        $result=$this->read($sourceHash,$scope);
        return is_array($result) && $result['local_review_status']==='REVIEWED_LOCAL_ALLOCATION' && is_array($result['allocation_packet'])
            ? $result : ($result instanceof WP_Error ? $result : self::error('not_current',409));
    }

    /**
     * Historical ordinary evidence is available only through its CURRENT reviewed inverse.
     * It is never promoted back to current(): the original economic source remains reversed.
     */
    public function reviewedOriginal(string $reversalHash,array $scope): array|WP_Error
    {
        $reversal=$this->current($reversalHash,$scope);
        if ($reversal instanceof WP_Error) return $reversal;
        $reference=$reversal['original_review_reference']??null;
        if (!is_array($reference)) return self::error('original_unavailable',409);
        $scope=$reversal['scope'];$proof=$this->originalProof($reference,$scope);
        if ($proof instanceof WP_Error) return $proof;
        $again=$this->current($reversalHash,$scope);
        if ($again instanceof WP_Error || Validator::canonicalJson($again)!==Validator::canonicalJson($reversal)) return self::error('changed',409);
        $final=$this->originalProof($reference,$scope);
        if ($final instanceof WP_Error || $proof!==$final) return self::error('changed',409);
        return self::project($reference['source_hash'],$scope,$proof['state'],'HISTORICAL_REVIEWED_LOCAL_ALLOCATION',$proof['packet']);
    }

    /** Only the existing signed/audited APPROVED stream supplies the original packet. */
    private function originalProof(array $reference,array $scope): array|WP_Error
    {
        try {
            $reference=AllocationReviewPayload::reference($reference);
            $hash=$reference['source_hash'];
            $scope=AllocationReviewPayload::scope($hash,$scope);
            $state=ReviewedLocalEvidence::approved(self::KIND,$scope['shop_key'],self::key($hash,$scope));
            if ($state instanceof WP_Error) return self::error('original_unavailable',409);
            if ($state['revision']!==$reference['revision'] || $state['proposal_hash']!==$reference['proposal_hash']
                || Validator::hash($state)!==$reference['state_hash']) return self::error('original_changed',409);
            // A typed ordinary v1 envelope excludes reversals and caller-supplied packets.
            if (!is_array($state['payload']??null) || ($state['payload']['schema']??null)!=='digiforge-finance-allocation-review-v1') return self::error('original_invalid',409);
            $packet=AllocationReviewPayload::packet($state['payload'],$hash,$scope);
            return ['state'=>$state,'packet'=>$packet];
        } catch (\InvalidArgumentException|\TypeError) { return self::error('original_invalid',409); }
    }

    private function readRequired(string $hash,array $scope): array|WP_Error
    {
        $result=$this->read($hash,$scope);return $result ?? self::error('missing',409);
    }

    private function validate(array $payload,string $sourceHash,array $scope): array|WP_Error
    {
        try {
            $packet=AllocationReviewPayload::packet($payload,$sourceHash,$scope);$lines=[];
            foreach ($packet['lines'] as $line) $lines[]=['target'=>$line['target'],'amount'=>$line['amount'],'base_amount'=>$line['base_amount']];
            $original=null;
            if (isset($payload['original_review_reference'])) {
                $original=$this->originalProof($payload['original_review_reference'],$scope);
                if ($original instanceof WP_Error) return $original;
            }
            $current=(new AllocationReadModel())->preview($sourceHash,$scope,$lines,$original['packet']??null);
            if ($current instanceof WP_Error || Validator::canonicalJson($current)!==Validator::canonicalJson($packet)) return self::error('stale',409);
            if ($original!==null && $original!==$this->originalProof($payload['original_review_reference'],$scope)) return self::error('original_changed',409);
            return $packet;
        } catch (\InvalidArgumentException|\TypeError) { return self::error('invalid_payload',409); }
    }

    private function admit(string $hash,array $scope,bool $decision=false): array|WP_Error
    {
        try { $scope=AllocationReviewPayload::scope($hash,$scope); }
        catch (\InvalidArgumentException|\TypeError) { return self::error('invalid_scope',400); }
        $caps=['manage_digiforge','manage_digiforge_finance'];if ($decision) $caps[]='manage_digiforge_settings';
        foreach ($caps as $cap) { $access=ShopAccess::authorize($scope['shop_key'],$cap);if ($access instanceof WP_Error) return self::denied($access); }
        return $scope;
    }
    private static function key(string $hash,array $scope): string { return 'allocation:v1:'.$scope['environment'].':'.$scope['business_id'].':'.$scope['store_id'].':'.$hash; }

    private static function project(string $hash,array $scope,array $state,string $status,?array $packet): array
    {
        $review=array_intersect_key($state,array_flip(['revision','version','proposal_hash','author_id','reviewer_id','effective_at','expires_at']));
        $review['state_hash']=Validator::hash($state);
        $result=['scope'=>$scope,'source_hash'=>$hash,'allocation_packet'=>$packet,'review'=>$review,'local_review_status'=>$status,
            'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,
            'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false];
        if (is_array($state['payload']['original_review_reference']??null)) $result['original_review_reference']=$state['payload']['original_review_reference'];
        return $result;
    }
    private static function denied(WP_Error $error): WP_Error { $data=$error->get_error_data();return self::error('unavailable',is_array($data)?(int)($data['status']??409):409); }
    private static function error(string $code,int $status): WP_Error
    {
        return new WP_Error('finance_allocation_review_'.$code,'A complete current independently reviewed local allocation is unavailable.',
            ['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,
                'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false]);
    }
}
