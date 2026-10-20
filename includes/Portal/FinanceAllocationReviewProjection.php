<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Whitelisted recovery metadata and redacted amounts, never a reusable stored packet. */
final class FinanceAllocationReviewProjection
{
    public static function fromResult(array $result,array $scope,string $sourceHash):array
    {
        if(($result['scope']??null)!==$scope||($result['source_hash']??null)!==$sourceHash
            ||($result['authenticity']??null)!=='UNKNOWN'||($result['source_completeness']??null)!=='UNKNOWN'
            ||!in_array($result['local_review_status']??null,['PENDING','REVIEWED_LOCAL_ALLOCATION','STALE','REVOKED','REJECTED','EXPIRED','NOT_YET_EFFECTIVE'],true))self::invalid();
        $out=['scope'=>$scope,'source_hash'=>$sourceHash,'local_review_status'=>$result['local_review_status'],'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN'];
        foreach(['actual_settlement_verified','period_approval_authorized','external_execution_authorized','money_movement_authorized','tax_filing_authorized'] as $key){if(($result[$key]??null)!==false)self::invalid();$out[$key]=false;}
        $review=$result['review']??null;if(!is_array($review))self::invalid();$out['review']=[];
        foreach(['revision','version','author_id','effective_at','expires_at'] as $key){if(!is_int($review[$key]??null)||$review[$key]<1)self::invalid();$out['review'][$key]=$review[$key];}
        if(!is_int($review['reviewer_id']??null)||$review['reviewer_id']<0||$review['expires_at']<=$review['effective_at'])self::invalid();$out['review']['reviewer_id']=$review['reviewer_id'];
        foreach(['proposal_hash','state_hash'] as $key){if(!is_string($review[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$review[$key])!==1)self::invalid();$out['review'][$key]=$review[$key];}
        if(array_key_exists('original_review_reference',$result)){
            $reference=$result['original_review_reference'];if(!is_array($reference))self::invalid();$keys=array_keys($reference);sort($keys);
            if($keys!==['proposal_hash','revision','source_hash','state_hash']||!is_int($reference['revision'])||$reference['revision']<1)self::invalid();
            foreach(['source_hash','state_hash','proposal_hash'] as $key)if(!is_string($reference[$key])||preg_match('/^[a-f0-9]{64}$/D',$reference[$key])!==1)self::invalid();
            $out['original_review_reference']=['source_hash'=>$reference['source_hash'],'revision'=>$reference['revision'],'state_hash'=>$reference['state_hash'],'proposal_hash'=>$reference['proposal_hash']];
        }
        if(!array_key_exists('allocation_packet',$result))self::invalid();$packet=$result['allocation_packet'];$out['allocation']=null;
        if($packet!==null){
            if(!is_array($packet)||!in_array($out['local_review_status'],['PENDING','REVIEWED_LOCAL_ALLOCATION'],true))self::invalid();
            $allocation=FinanceImportPreviewProjection::fromPacket($packet,$scope,$sourceHash);
            $out['allocation']=array_intersect_key($allocation,array_flip(['packet_schema','state','review_status','source_ledger_id','proposal_hash','currency','base_currency','allocated_amount','allocated_base_amount','lines']));
        }elseif(in_array($out['local_review_status'],['PENDING','REVIEWED_LOCAL_ALLOCATION'],true))self::invalid();
        return $out;
    }
    private static function invalid():never {throw new \InvalidArgumentException('A current redacted local allocation review result is required.');}
}
