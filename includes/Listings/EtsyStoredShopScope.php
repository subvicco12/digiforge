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
  $known="((BINARY $local IN (BINARY 'digital',BINARY 'DigiCraftifyDigital') AND BINARY $id=BINARY '67757764' AND BINARY $name=BINARY 'KinetiqMatrixDesigns') OR (BINARY $local IN (BINARY 'personalized_pod',BINARY 'DigiCraftifyGoods') AND BINARY $id=BINARY '68031896' AND BINARY $name=BINARY 'DigicraftifyShop'))";
  return "(BINARY $local=BINARY $external OR EXISTS (SELECT 1 FROM ".Tables::integrations()." scope_integration WHERE BINARY scope_integration.provider=BINARY 'etsy' AND BINARY scope_integration.status=BINARY 'CONFIGURED' AND BINARY scope_integration.environment=BINARY $environment AND CASE WHEN JSON_VALID($config) THEN JSON_EXTRACT($config,'$._connection_test.ok')=true AND BINARY $id=BINARY $external AND ($known OR BINARY $name=BINARY $local) ELSE false END))";
 }
 public static function orderPredicate(string $shop,string $alias):string {
  global $wpdb;return self::matches($wpdb->prepare('%s',$shop),$alias.'.shop_reference',$alias.'.environment');
 }
}
