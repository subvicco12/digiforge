<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Orchestrates the complete credential-aware Etsy pre-network path.
 * No HTTP request, adapter call, ledger mutation, or external execution occurs.
 */
final class EtsyControlledTransportOrchestrator
{
    /** @return array<string,mixed>|WP_Error */
    public function prepare(
        array $prepared,
        array $operation,
        array $tokenMetadata,
        string $method,
        string $endpoint,
        array $headers=[]
    ): array|WP_Error {
        $handoff=EtsyConsumedPermitHandoff::validate($prepared);
        if ($handoff instanceof WP_Error) return $handoff;

        $invocation=EtsyAdapterInvocationPlan::build($handoff,$operation);
        if ($invocation instanceof WP_Error) return $invocation;

        $acquisition=EtsyTokenAcquisitionGate::evaluate($tokenMetadata,$invocation);
        if ($acquisition instanceof WP_Error) return $acquisition;

        $scope=EtsyScopedCredentialAccess::authorize($acquisition,$invocation);
        if ($scope instanceof WP_Error) return $scope;

        $integrationId=(int)($scope['integration_id']??0);
        $request=EtsyHttpRequestPlan::build($invocation,$method,$endpoint,$headers,$integrationId);
        if ($request instanceof WP_Error) return $request;
        $transport=(new EtsyCredentialAwareTransport())->prepare($request,$scope);
        if ($transport instanceof WP_Error) return $transport;

        return [
            'state'=>'ETSY_CONTROLLED_TRANSPORT_PREPARED',
            'operation_id'=>(int)($invocation['operation_id']??0),
            'integration_id'=>$integrationId,
            'request_plan'=>$request,
            'transport'=>$transport,
            'credential_material_exposed'=>false,
            'authorization_header_constructed'=>false,
            'network_request_permitted'=>false,
            'network_request_attempted'=>false,
            'sent_transition_permitted'=>false,
            'ledger_state_change_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }
}
