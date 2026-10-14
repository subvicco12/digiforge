<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use DigiForge\Database\Tables;
/** Local identity joins from stored successful preflight; no provider lookup or activation. */
final class EtsyStoredShopScope {
 /** Expressions are internal table aliases/quoted literals, never request SQL. */
 public static function matches(string $local,string $external,string $environment):string {
  $config='scope_integration.config';
  $id="JSON_UNQUOTE(JSON_EXTRACT($config,'$._connection_test.details.shop_id'))";
  $name="JSON_UNQUOTE(JSON_EXTRACT($config,'$._connection_test.details.shop_name'))";
  // CredentialEvidenceVersion::fromMetadata hashes sorted name:fingerprint lines.
  // Compare inside the same statement so a credential rotation cannot reuse an
  // earlier successful shop preflight. No secret material is read or exposed.
  $secrets=Tables::integration_secrets();
  $valid="scope_secret.secret_name REGEXP '^[a-z][a-z0-9_-]{0,63}$' AND BINARY scope_secret.secret_name=BINARY LOWER(scope_secret.secret_name) AND scope_secret.fingerprint REGEXP '^[a-f0-9]{16}$' AND BINARY scope_secret.fingerprint=BINARY LOWER(scope_secret.fingerprint)";
  $version="(SELECT SHA2(GROUP_CONCAT(CONCAT(scope_secret.secret_name,':',scope_secret.fingerprint) ORDER BY BINARY scope_secret.secret_name SEPARATOR 0x0a),256) FROM $secrets scope_secret WHERE scope_secret.integration_id=scope_integration.id HAVING COUNT(*)>0 AND SUM(NOT ($valid))=0 AND COUNT(DISTINCT BINARY scope_secret.secret_name)=COUNT(*))";
  $storedVersion="JSON_UNQUOTE(JSON_EXTRACT($config,'$._connection_test.credential_evidence_version'))";
  $known="((BINARY $local IN (BINARY 'digital',BINARY 'DigiCraftifyDigital') AND BINARY $id=BINARY '67757764' AND BINARY $name=BINARY 'KinetiqMatrixDesigns') OR (BINARY $local IN (BINARY 'personalized_pod',BINARY 'DigiCraftifyGoods') AND BINARY $id=BINARY '68031896' AND BINARY $name=BINARY 'DigicraftifyShop'))";
  return "(BINARY $local=BINARY $external OR EXISTS (SELECT 1 FROM ".Tables::integrations()." scope_integration WHERE BINARY scope_integration.provider=BINARY 'etsy' AND BINARY scope_integration.status=BINARY 'CONFIGURED' AND BINARY scope_integration.environment=BINARY $environment AND CASE WHEN JSON_VALID($config) THEN JSON_EXTRACT($config,'$._connection_test.ok')=true AND BINARY $storedVersion=BINARY $version AND BINARY $id=BINARY $external AND ($known OR BINARY $name=BINARY $local) AND NOT EXISTS (SELECT 1 FROM ".Tables::integrations()." scope_conflict WHERE BINARY scope_conflict.provider=BINARY 'etsy' AND BINARY scope_conflict.status=BINARY 'CONFIGURED' AND BINARY scope_conflict.environment=BINARY $environment AND scope_conflict.id<>scope_integration.id AND CASE WHEN JSON_VALID(scope_conflict.config) THEN JSON_EXTRACT(scope_conflict.config,'$._connection_test.ok')=true AND BINARY JSON_UNQUOTE(JSON_EXTRACT(scope_conflict.config,'$._connection_test.details.shop_id'))=BINARY $external ELSE false END) ELSE false END))";
 }
 public static function orderPredicate(string $shop,string $alias):string {
  global $wpdb;return self::matches($wpdb->prepare('%s',$shop),$alias.'.shop_reference',$alias.'.environment');
 }
}
