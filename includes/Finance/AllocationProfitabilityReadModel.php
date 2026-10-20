<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use WP_Error;

/** Read-only profitability of an explicit bounded reviewed subset, never a complete financial cohort. */
final class AllocationProfitabilityReadModel
{
    private const DIMENSIONS=['business','store','shop','order','product','product_version','listing','variant'];

    /**
     * References pin source_hash and the review's revision/state_hash/proposal_hash.
     * current() independently rebuilds source, ownership and targets; callers cannot supply packets.
     * The final sweep detects observed drift, not every possible future concurrent write.
     */
    public function snapshot(array $references,array $scope): array|WP_Error
    {
        try {
            $references=self::references($references);$scope=self::scope($scope,$references[0]['source_hash']);
            $ownership=array_intersect_key($scope,array_flip(['shop_key','business_id','store_id','environment']));
            $service=new AllocationReview();$current=[];$amounts=[];$buckets=[];$provenance=[];$lineCount=0;
            foreach ($references as $reference) {
                $review=$service->current($reference['source_hash'],$ownership);
                if ($review instanceof WP_Error) return self::error('review_unavailable');
                foreach (['revision','state_hash','proposal_hash'] as $field) {
                    if (($review['review'][$field]??null)!==$reference[$field]) return self::error('review_changed');
                }
                $packet=$review['allocation_packet'];$source=DeclarationContract::fromStored($packet['source']);
                if ($source['declaration_hash']!==$reference['source_hash'] || $source['environment']!==$scope['environment']
                    || $source['base_currency']!==$scope['base_currency'] || $source['reversal_of_hash']!==''
                    || $packet['reversal_of_proposal_hash']!=='' || $packet['lines']===[]) return self::error('source_conflict');
                foreach (['shop_key','business_id','store_id'] as $field) {
                    if ($source['scope'][$field]!==$scope[$field]) return self::error('scope_conflict');
                }
                $date=substr($source['occurred_at'],0,10);
                if ($date<$scope['period_start'] || $date>$scope['period_end']) return self::error('period_conflict');
                $current[]=$review;
                foreach ($packet['lines'] as $line) {
                    if (++$lineCount>200) return self::error('limit_exceeded');
                    $target=$line['target'];$dimensions=self::dimensions($target,$scope);
                    $amount=['category'=>$source['category'],'base_amount'=>$line['base_amount']];$amounts[]=$amount;
                    $proof=['source_hash'=>$reference['source_hash'],'source_ledger_id'=>$packet['source_ledger_id'],
                        'review'=>$reference,'allocation_proposal_hash'=>$packet['proposal_hash'],
                        'target_reference_hash'=>$line['reference_hash'],'target'=>$target,
                        'variant_attribution'=>$target['provider_mapping_id']===0?'NOT_APPLICABLE':'MAPPING_QUALIFIED',
                        'occurred_at'=>$source['occurred_at'],'category'=>$source['category'],
                        'currency'=>$source['currency'],'amount'=>$line['amount'],
                        'base_currency'=>$source['base_currency'],'base_amount'=>$line['base_amount']];
                    $provenance[]=$proof;
                    foreach ($dimensions as $dimension=>$id) {
                        if ($id===null) continue; // Digital sources have no POD variant; no variant is invented.
                        $buckets[$dimension][(string)$id]['dimension_id']=$id;
                        $buckets[$dimension][(string)$id]['amounts'][]=$amount;
                        $buckets[$dimension][(string)$id]['provenance'][]=$proof;
                    }
                }
            }
            // No declarations or transaction identities are synthesized for the existing matcher.
            $totals=ProvisionalProfitMetrics::fromAmounts($amounts);
            $groups=array_fill_keys(self::DIMENSIONS,[]);
            foreach ($buckets as $dimension=>$values) {
                ksort($values,SORT_STRING);
                foreach ($values as $bucket) {
                    $group=['dimension_id'=>$bucket['dimension_id'],'line_count'=>count($bucket['amounts']),
                        'metrics'=>ProvisionalProfitMetrics::fromAmounts($bucket['amounts']),'provenance'=>$bucket['provenance']];
                    if ($dimension==='variant') {
                        $target=$bucket['provenance'][0]['target'];
                        $group['provider_mapping_id']=$target['provider_mapping_id'];$group['variant_ref']=$target['variant_ref'];
                    }
                    $groups[$dimension][]=$group;
                }
            }
            foreach ($references as $i=>$reference) {
                $again=$service->current($reference['source_hash'],$ownership);
                if ($again instanceof WP_Error || Validator::canonicalJson($again)!==Validator::canonicalJson($current[$i])) return self::error('changed');
            }
            return ['schema'=>'finance-reviewed-allocation-profitability-v1','calculation_version'=>'declared-profit-v2-exact-1',
                'accounting_convention'=>'signed-bucket-v1','query_state'=>'AVAILABLE','state'=>'PROVISIONAL','scope'=>$scope,
                'coverage'=>['state'=>'EXPLICIT_REVIEWED_SUBSET','source_count'=>count($references),'line_count'=>$lineCount,
                    'full_period_coverage_verified'=>false,'references'=>$references],
                'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','totals'=>$totals,'groups'=>$groups,'provenance'=>$provenance,
                'actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,
                'money_movement_authorized'=>false,'tax_filing_authorized'=>false];
        } catch (\InvalidArgumentException|\TypeError) {
            return self::error('invalid_evidence',400);
        }
    }

    private static function scope(array $scope,string $sourceHash): array
    {
        self::keys($scope,['shop_key','business_id','store_id','environment','period_start','period_end','base_currency']);
        AllocationReviewPayload::scope($sourceHash,array_intersect_key($scope,array_flip(['shop_key','business_id','store_id','environment'])));
        foreach (['period_start','period_end'] as $field) {
            if (!is_string($scope[$field]) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$scope[$field])!==1
                || Validator::date($scope[$field])!==$scope[$field]) throw new \InvalidArgumentException('Canonical UTC period required.');
        }
        if ($scope['period_start']>$scope['period_end'] || !is_string($scope['base_currency'])
            || preg_match('/^[A-Z]{3}$/D',$scope['base_currency'])!==1) throw new \InvalidArgumentException('Explicit period and base currency required.');
        return $scope;
    }

    private static function references(array $references): array
    {
        if (!array_is_list($references) || $references===[] || count($references)>20) throw new \InvalidArgumentException('One to twenty explicit current review references required.');
        $sources=[];$reviews=[];
        foreach ($references as $reference) {
            if (!is_array($reference)) throw new \InvalidArgumentException('Typed review reference required.');
            self::keys($reference,['source_hash','revision','state_hash','proposal_hash']);
            if (!is_int($reference['revision']) || $reference['revision']<1) throw new \InvalidArgumentException('Exact review revision required.');
            foreach (['source_hash','state_hash','proposal_hash'] as $field) {
                if (!is_string($reference[$field]) || preg_match('/^[a-f0-9]{64}$/D',$reference[$field])!==1) throw new \InvalidArgumentException('Exact review hashes required.');
            }
            if (isset($sources[$reference['source_hash']]) || isset($reviews[$reference['state_hash']])) throw new \InvalidArgumentException('Duplicate sources or reviews are not a financial cohort.');
            $sources[$reference['source_hash']]=true;$reviews[$reference['state_hash']]=true;
        }
        return $references;
    }

    private static function dimensions(array $target,array $scope): array
    {
        foreach (['shop_key','business_id','store_id','environment'] as $field) {
            if (($target[$field]??null)!==$scope[$field]) throw new \InvalidArgumentException('Target scope conflict.');
        }
        foreach (['order_id','order_line_item_id','product_id','product_version_id','listing_id'] as $field) {
            if (!is_int($target[$field]??null) || $target[$field]<1) throw new \InvalidArgumentException('Complete unambiguous target dimensions required.');
        }
        $mapping=$target['provider_mapping_id']??null;$variant=$target['variant_ref']??null;
        if (!is_int($mapping) || $mapping<0 || !is_string($variant) || ($mapping===0)!==($variant==='')) throw new \InvalidArgumentException('Exact mapping-qualified variant required.');
        // The current reference hash pins the provider mapping, so equal labels on different providers/mappings stay separate.
        return ['business'=>$target['business_id'],'store'=>$target['store_id'],'shop'=>$target['shop_key'],
            'order'=>$target['order_id'],'product'=>$target['product_id'],'product_version'=>$target['product_version_id'],
            'listing'=>$target['listing_id'],'variant'=>$mapping===0?null:$mapping.':'.$variant];
    }

    private static function keys(array $value,array $expected): void
    {
        $keys=array_keys($value);sort($keys);sort($expected);
        if ($keys!==$expected) throw new \InvalidArgumentException('Exact profitability contract fields required.');
    }
    private static function error(string $code,int $status=409): WP_Error
    {
        return new WP_Error('finance_allocation_profitability_'.$code,'A complete current version-pinned reviewed subset is required.',
            ['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','totals'=>null,
                'actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,
                'money_movement_authorized'=>false,'tax_filing_authorized'=>false]);
    }
}
