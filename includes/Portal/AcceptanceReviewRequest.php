<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Strict transport envelope; semantic validation stays in ReviewedAcceptanceContract. */
final class AcceptanceReviewRequest
{
    public static function parse(string $raw, bool $decision): ?array
    {
        if (strlen($raw)>32768) return null;
        $body=json_decode($raw,true,64);
        $fields=$decision ? ['criterion','scope','proposal_hash','decision'] : ['input','effective_at','expires_at'];
        $fields=array_merge($fields,['expected_revision','request_id','confirm']);
        if (!is_array($body) || count($body)!==count($fields) || array_diff(array_keys($body),$fields)!==[]
            || ($body['confirm']??null)!=='CONFIRM_LOCAL_ACCEPTANCE_REVIEW'
            || !is_int($body['expected_revision']??null) || $body['expected_revision']<0
            || !is_string($body['request_id']??null) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$body['request_id'])!==1) return null;
        if ($decision) {
            if (!is_string($body['criterion']) || !is_string($body['scope'])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}$/D',$body['scope'])!==1
                || !is_string($body['proposal_hash']) || preg_match('/^[a-f0-9]{64}$/D',$body['proposal_hash'])!==1
                || !in_array($body['decision'],['APPROVED','REJECTED','REVOKED'],true)) return null;
        } elseif (!is_array($body['input']) || array_is_list($body['input']) || !is_int($body['effective_at'])
            || !is_int($body['expires_at']) || $body['effective_at']<1 || $body['expires_at']<=$body['effective_at']) return null;
        return $body;
    }
}
