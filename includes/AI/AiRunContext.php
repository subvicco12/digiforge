<?php
declare(strict_types=1);
namespace DigiForge\AI;

/** Immutable caller-supplied run boundary for per-run AI governance. */
final class AiRunContext
{
    /** @return array{run_id:string,started_at:string}|\WP_Error */
    public static function normalize(string $runId,string $startedAt):array|\WP_Error
    {
        $runId=sanitize_key($runId);$startedAt=trim($startedAt);
        if($runId===''||strlen($runId)>100)return new \WP_Error('invalid_ai_run_context','A bounded run_id is required.');
        $ts=strtotime($startedAt);if($startedAt===''||$ts===false)return new \WP_Error('invalid_ai_run_context','A valid run started_at timestamp is required.');
        if($ts>time()+300)return new \WP_Error('invalid_ai_run_context','AI run cannot start in the future.');
        return ['run_id'=>$runId,'started_at'=>gmdate('Y-m-d H:i:s',$ts)];
    }
}
