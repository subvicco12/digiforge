<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Mock-only end-to-end orchestration of the controlled Etsy pre-network path.
 * It cannot transition SENT because the mock adapter never attempts a request.
 */
final class EtsyMockExecutionOrchestrator
{
    /** @return array<string,mixed>|WP_Error */
    public static function run(
        array $prepared,
        array $operation,
        EtsyMockExecutionAdapter $adapter,
        string $method,
        string $endpoint,
        array $headers=[]
    ): array|WP_Error {
        $handoff=EtsyConsumedPermitHandoff::validate($prepared);
        if ($handoff instanceof WP_Error) return $handoff;

        $invocation=EtsyAdapterInvocationPlan::build($handoff,$operation);
        if ($invocation instanceof WP_Error) return $invocation;

        $request=EtsyHttpRequestPlan::build($invocation,$method,$endpoint,$headers);
        if ($request instanceof WP_Error) return $request;
        if (($request['network_request_permitted']??null)!==false) {
            return self::error('network_boundary','Mock orchestration requires network execution to remain prohibited.');
        }

        $payload=(array)($invocation['payload']??[]);
        $payload['_operation_id']=(int)$invocation['operation_id'];
        $result=$adapter->execute((array)$invocation['permit'],$payload);
        if ($result instanceof WP_Error) return $result;

        if (($result['external_request_attempted']??null)!==false
            || ($result['external_execution_performed']??null)!==false
            || ($result['network_request_permitted']??null)!==false) {
            return self::error('mock_escape','Mock adapter must never claim network or external execution.');
        }

        return [
            'state'=>'ETSY_MOCK_EXECUTION_COMPLETED',
            'operation_id'=>(int)$invocation['operation_id'],
            'request_plan'=>$request,
            'adapter_result'=>$result,
            'sent_transition_permitted'=>false,
            'ledger_state_change_permitted'=>false,
            'external_attempt_evidence_available'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_mock_orchestration_'.$code,$message,['status'=>409]);
    }
}
