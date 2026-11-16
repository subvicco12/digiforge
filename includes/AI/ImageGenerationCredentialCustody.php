<?php
declare(strict_types=1);
namespace DigiForge\AI;
use WP_Error;

/**
 * Server-injected NETWORK boundary, deliberately without a default implementation.
 *
 * Implementations MUST coordinate every credential rotation/deletion, connector
 * disable, authorization/STOP revocation and source mutation writer. Acquire an
 * exclusive generation lease for these exact pins, validate it before invoking
 * $dispatch once, retain it through the callback, and check release. A revoked,
 * expired, unavailable or uncertain lease MUST return WP_Error. The callback is
 * not retained or queued. No caller transaction may be committed/reset here.
 *
 * An advisory lock ignored by writers is NOT an implementation of this contract.
 * Until an independently reviewed custodian is wired by server code, NETWORK
 * execution is unavailable even if other switches are enabled. Offline simulators
 * do not use this boundary or read credentials.
 */
interface ImageGenerationCredentialCustody
{
    public function withExclusiveGeneration(array $immutableIdentity, callable $dispatch): array|WP_Error;
}
