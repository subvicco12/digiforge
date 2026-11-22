<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialEvidenceVersion;

/** Offline test evidence only; the ciphertext is never decrypted or sent to Etsy. */
final class SyntheticEtsyIdentity
{
    private const FINGERPRINT = '0123456789abcdef';

    public static function config(string $shopId, string $shopName): string
    {
        $version=CredentialEvidenceVersion::fromMetadata([['secret_name'=>'access_token','fingerprint'=>self::FINGERPRINT]]);
        return (string)wp_json_encode(['_connection_test'=>[
            'ok'=>true,'checked_at'=>current_time('mysql',true),
            'credential_evidence_version'=>$version,
            'details'=>['shop_id'=>$shopId,'shop_name'=>$shopName],
        ]]);
    }

    public static function credential(int $integrationId): void
    {
        global $wpdb;
        if($integrationId<1||$wpdb->insert(Tables::integration_secrets(),[
            'integration_id'=>$integrationId,'secret_name'=>'access_token',
            'ciphertext'=>'synthetic-never-used','fingerprint'=>self::FINGERPRINT,
            'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true),
        ])!==1)throw new RuntimeException('Synthetic credential metadata fixture could not be inserted.');
    }
}
