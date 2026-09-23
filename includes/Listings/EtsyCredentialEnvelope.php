<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Opaque in-process envelope for one transient Etsy access token.
 * The secret cannot be serialized, string-cast, JSON encoded, logged by value, or persisted by this class.
 */
final class EtsyCredentialEnvelope implements \JsonSerializable
{
    private bool $consumed=false;

    private function __construct(
        private string $token,
        private readonly int $integrationId,
        private readonly int $operationId
    ) {}

    /** @return self|WP_Error */
    public static function seal(array $scope,string $token): self|WP_Error
    {
        if (($scope['state']??'')!=='ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED'
            || ($scope['single_use']??null)!==true
            || ($scope['credential_name']??'')!=='access_token'
            || ($scope['credential_retrieval_performed']??null)!==false
            || ($scope['credential_persistence_permitted']??null)!==false
            || ($scope['credential_logging_permitted']??null)!==false) {
            return self::error('scope','Valid single-use Etsy credential scope is required.');
        }
        $integrationId=(int)($scope['integration_id']??0);
        $operationId=(int)($scope['operation_id']??0);
        if ($integrationId<1 || $operationId<1 || $token==='') {
            return self::error('material','Valid scoped token material is required.');
        }
        return new self($token,$integrationId,$operationId);
    }

    /**
     * Consume exactly once inside a future credential-aware transport boundary.
     * The callback return value must not contain the token.
     */
    public function consume(int $integrationId,int $operationId,callable $consumer): mixed
    {
        if ($this->consumed || $integrationId!==$this->integrationId || $operationId!==$this->operationId) {
            return self::error('consume','Credential envelope is unavailable for this scope.');
        }
        $this->consumed=true;
        $token=$this->token;
        $this->token='';
        return $consumer($token);
    }

    public function jsonSerialize(): array
    {
        return ['state'=>'ETSY_CREDENTIAL_ENVELOPE','redacted'=>true,'consumed'=>$this->consumed];
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function __debugInfo(): array
    {
        return ['state'=>'ETSY_CREDENTIAL_ENVELOPE','redacted'=>true,'consumed'=>$this->consumed];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_credential_envelope_'.$code,$message,['status'=>409]);
    }
}
