<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use InvalidArgumentException;

/** Typed bounded wrapper around the complete existing explicit proposal; never financial authority. */
final class AllocationReviewPayload
{
    private const SCHEMA='digiforge-finance-allocation-review-v1';
    private const REVERSAL_SCHEMA='digiforge-finance-allocation-review-v2';

    public static function scope(string $sourceHash,array $scope): array
    {
        self::keys($scope,['shop_key','business_id','store_id','environment']);
        if (preg_match('/^[a-f0-9]{64}$/D',$sourceHash)!==1
            || !is_string($scope['shop_key']) || !in_array($scope['shop_key'],DeclarationContract::SHOPS,true)
            || !is_int($scope['business_id']) || $scope['business_id']<1
            || !is_int($scope['store_id']) || $scope['store_id']<1
            || !is_string($scope['environment']) || Validator::environment($scope['environment'])!==$scope['environment']) self::fail();
        return ['shop_key'=>$scope['shop_key'],'business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],'environment'=>$scope['environment']];
    }

    public static function create(string $sourceHash,array $scope,array $packet,?array $originalReviewReference=null): array
    {
        $payload=['schema'=>self::SCHEMA,'scope'=>self::scope($sourceHash,$scope),'source_hash'=>$sourceHash,'packet'=>$packet];
        if ($originalReviewReference!==null) {
            $payload['schema']=self::REVERSAL_SCHEMA;
            $payload['original_review_reference']=self::reference($originalReviewReference);
        }
        self::packet($payload,$sourceHash,$scope);
        return $payload;
    }

    /** Shape/integrity validation only. Consumers must also rebuild from current independent storage. */
    public static function packet(array $payload,string $sourceHash,array $scope): array
    {
        $scope=self::scope($sourceHash,$scope);
        $reversal=($payload['schema']??null)===self::REVERSAL_SCHEMA;
        self::keys($payload,$reversal?['schema','scope','source_hash','packet','original_review_reference']:['schema','scope','source_hash','packet']);self::bounded($payload);
        if (strlen(Validator::canonicalJson($payload))>32768 || !in_array($payload['schema'],[self::SCHEMA,self::REVERSAL_SCHEMA],true)
            || !is_array($payload['scope']) || Validator::canonicalJson($payload['scope'])!==Validator::canonicalJson($scope)
            || $payload['source_hash']!==$sourceHash || !is_array($payload['packet'])) self::fail();
        $packet=$payload['packet'];
        self::keys($packet,['schema','method','source_ledger_id','source','lines','allocated_amount','allocated_base_amount',
            'reversal_of_proposal_hash','state','reconciliation_state','review_status','authenticity','source_completeness',
            'actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized',
            'tax_filing_authorized','proposal_hash','idempotency_key']);
        if ($packet['schema']!=='digiforge-finance-allocation-proposal-v1' || $packet['method']!=='EXPLICIT_AMOUNTS_ONLY'
            || !is_int($packet['source_ledger_id']) || $packet['source_ledger_id']<1 || !is_array($packet['source'])
            || !is_array($packet['lines']) || !array_is_list($packet['lines']) || $packet['lines']===[] || count($packet['lines'])>100
            || ($reversal ? (!is_string($packet['reversal_of_proposal_hash']) || preg_match('/^[a-f0-9]{64}$/D',$packet['reversal_of_proposal_hash'])!==1) : $packet['reversal_of_proposal_hash']!=='') || $packet['state']!=='PROVISIONAL'
            || $packet['reconciliation_state']!==($reversal?'EXPLICIT_REVERSAL_PROPOSAL':'EXPLICIT_SUM_MATCH') || $packet['review_status']!=='UNREVIEWED'
            || $packet['authenticity']!=='UNKNOWN' || $packet['source_completeness']!=='UNKNOWN') self::fail();
        foreach (['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $field) if ($packet[$field]!==false) self::fail();
        $source=DeclarationContract::fromStored($packet['source']);
        if ($source['declaration_hash']!==$sourceHash || $source['environment']!==$scope['environment']
            || ($reversal ? ($source['reversal_of_hash']==='' || !is_array($payload['original_review_reference'])
                || self::reference($payload['original_review_reference'])['source_hash']!==$source['reversal_of_hash']) : $source['reversal_of_hash']!=='')
            || $packet['allocated_amount']!==$source['amount'] || $packet['allocated_base_amount']!==$source['base_amount']) self::fail();
        foreach (['shop_key','business_id','store_id'] as $field) if ($source['scope'][$field]!==$scope[$field]) self::fail();
        foreach ($packet['lines'] as $line) {
            if (!is_array($line)) self::fail();
            self::keys($line,['target','reference_hash','amount','base_amount']);
            if (!is_array($line['target']) || !is_string($line['reference_hash']) || preg_match('/^[a-f0-9]{64}$/D',$line['reference_hash'])!==1
                || !is_string($line['amount']) || ExactDecimal::money($line['amount'])!==$line['amount']
                || !is_string($line['base_amount']) || ExactDecimal::money($line['base_amount'])!==$line['base_amount']) self::fail();
        }
        $hash=$packet['proposal_hash'];$material=$packet;unset($material['proposal_hash'],$material['idempotency_key']);
        if (!is_string($hash) || !hash_equals(Validator::hash($material),$hash) || $packet['idempotency_key']!=='finance_alloc:'.$hash) self::fail();
        return $packet;
    }

    /** A reference is a pin into the signed reviewed stream, never a caller packet. */
    public static function reference(array $reference): array
    {
        self::keys($reference,['source_hash','revision','state_hash','proposal_hash']);
        if (!is_int($reference['revision']) || $reference['revision']<1) self::fail();
        foreach (['source_hash','state_hash','proposal_hash'] as $field) {
            if (!is_string($reference[$field]) || preg_match('/^[a-f0-9]{64}$/D',$reference[$field])!==1) self::fail();
        }
        return ['source_hash'=>$reference['source_hash'],'revision'=>$reference['revision'],'state_hash'=>$reference['state_hash'],'proposal_hash'=>$reference['proposal_hash']];
    }

    private static function bounded(array $value,int $depth=0): void
    {
        if ($depth>12 || count($value)>100) self::fail();
        foreach ($value as $key=>$item) {
            if (is_string($key) && preg_match('/password|secret|token|credential|authorization|private.?key/i',$key)) self::fail();
            if (is_array($item)) self::bounded($item,$depth+1);
            elseif (!is_null($item) && !is_bool($item) && !is_int($item) && !is_string($item)) self::fail();
            elseif (is_string($item) && (strlen($item)>4096 || preg_match('//u',$item)!==1)) self::fail();
        }
        Validator::structured($value);
    }
    private static function keys(array $value,array $expected): void { $keys=array_keys($value);sort($keys);sort($expected);if ($keys!==$expected) self::fail(); }
    private static function fail(): never { throw new InvalidArgumentException('Exact bounded versioned allocation review evidence required.'); }
}
