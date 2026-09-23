<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Disabled adapter harness for contract testing only.
 *
 * It accepts injected sanitized mock transport metadata. It has no network
 * primitive and cannot be configured to contact Etsy.
 */
final class EtsyMockExecutionAdapter implements EtsyExecutionAdapter
{
    /** @param array<string,mixed> $mockResponse */
    public function __construct(private array $mockResponse) {}

    /** @return array<string,mixed>|WP_Error */
    public function execute(array $permit,array $payload): array|WP_Error
    {
        if (($permit['state']??'')!=='ADAPTER_CALL_PERMITTED' || ($permit['nonce_consumed']??null)!==true) {
            return $this->error('permit','Mock adapter requires a consumed adapter-call permit.');
        }
        if (($permit['external_execution_performed']??null)!==false) {
            return $this->error('permit_state','Permit must not claim prior external execution.');
        }

        $operationId=(int)($payload['_operation_id']??0);
        if ($operationId<1) return $this->error('operation','Mock payload requires a positive operation identity.');

        $response=EtsyHttpOutcome::classify($this->mockResponse);
        if ($response instanceof WP_Error) return $response;

        return [
            'state'=>'ETSY_MOCK_ADAPTER_COMPLETED',
            'operation_id'=>$operationId,
            'transport_mode'=>'MOCK_ONLY',
            'outcome'=>$response,
            'adapter_invoked'=>true,
            'external_request_attempted'=>false,
            'external_execution_performed'=>false,
            'network_request_permitted'=>false,
            'credentials_exposed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_mock_adapter_'.$code,$message,['status'=>409]);
    }
}
