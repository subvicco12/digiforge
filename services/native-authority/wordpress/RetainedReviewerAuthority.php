<?php
declare(strict_types=1);
namespace DigiForge\NativeAuthority\WordPress;
final class RetainedReviewerAuthority
{
    /** Version of the local interface only; never an authority epoch. */
    public const ABI_VERSION='db-gpo-native-reviewer-v1';
    public static function capture(string $shop,int $reviewerId):RetainedReviewerLease|\WP_Error{return RetainedReviewerLease::capture($shop,$reviewerId);}
    public static function error(string $reason):\WP_Error {
        $data=['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false];
        if($reason==='commit_outcome_mismatch')$data+=['transaction_outcome'=>'UNKNOWN','committed'=>null,'changed'=>null];
        return new \WP_Error('retained_reviewer_'.$reason,'Retained reviewer authority is unavailable.',$data);
    }
}
