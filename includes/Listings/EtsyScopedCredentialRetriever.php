<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository;
use WP_Error;

/**
 * Narrow credential retrieval boundary for the controlled Etsy execution path.
 * It may retrieve only the access_token for one authorized integration/operation
 * and immediately seals it in an opaque single-use envelope.
 */
final class EtsyScopedCredentialRetriever
{
    /** @return EtsyCredentialEnvelope|WP_Error */
    public function retrieve(array $scope): EtsyCredentialEnvelope|WP_Error
    {
        if (($scope['state']??'')!=='ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED'
            || ($scope['single_use']??null)!==true
            || ($scope['credential_name']??'')!=='access_token'
            || ($scope['general_credential_access']??null)!==false
            || ($scope['refresh_token_access']??null)!==false
            || ($scope['credential_persistence_permitted']??null)!==false
            || ($scope['credential_logging_permitted']??null)!==false
            || ($scope['credential_retrieval_performed']??null)!==false
            || ($scope['network_request_permitted']??null)!==false
            || ($scope['external_execution_performed']??null)!==false) {
            return self::error('scope','Valid unused operation-scoped Etsy credential authorization is required.');
        }

        $integrationId=(int)($scope['integration_id']??0);
        $operationId=(int)($scope['operation_id']??0);
        if ($integrationId<1 || $operationId<1) {
            return self::error('identity','Valid integration and operation identifiers are required.');
        }

        global $wpdb;
        $row=$wpdb->get_row(
            $wpdb->prepare(
                'SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id = %d AND secret_name = %s LIMIT 1',
                $integrationId,
                'access_token'
            ),
            ARRAY_A
        );
        if (!is_array($row) || empty($row['ciphertext'])) {
            return self::error('not_found','Authorized Etsy access token is unavailable.');
        }

        try {
            $token=CredentialVault::decrypt(
                (string)$row['ciphertext'],
                Repository::secretContext($integrationId,'access_token')
            );
        } catch (\Throwable $e) {
            return self::error('decrypt','Authorized Etsy access token could not be decrypted.');
        }

        if ($token==='') {
            return self::error('empty','Authorized Etsy access token is empty.');
        }

        $envelope=EtsyCredentialEnvelope::seal($scope,$token);
        $token='';
        return $envelope;
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_scoped_retrieval_'.$code,$message,['status'=>409]);
    }
}
