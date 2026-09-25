<?php
declare(strict_types=1);
namespace DigiForge\Queue;

/** Pure read model for queue states requiring operator/recovery attention. */
final class RecoveryAdminSummary
{
    /** @param array<string,mixed> $counts @return array<string,mixed> */
    public static function summarize(array $counts, bool $queryOk): array
    {
        if (! $queryOk) {
            return ['query_ok'=>false,'attention_total'=>0,'states'=>[],'recovery_required'=>true,'external_actions_performed'=>false];
        }
        $states=[];
        foreach (['FAILED','BLOCKED','HUMAN_REVIEW','DEAD_LETTER'] as $state) {
            $value=$counts[$state]??0;
            $states[$state]=is_int($value)&&$value>=0?$value:0;
        }
        return [
            'query_ok'=>true,
            'attention_total'=>array_sum($states),
            'states'=>$states,
            'recovery_required'=>array_sum($states)>0,
            'external_actions_performed'=>false,
        ];
    }
}
