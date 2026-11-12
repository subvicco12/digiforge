<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Additive component contract; no lower marker or silent repair of incomplete tables. */
final class ScopedPolicySchema
{
    public const VERSION = 23;
    public static function migrateIfNeeded(): bool
    {
        return ComponentSchema::ensure(Tables::scoped_capability_policies(), self::VERSION, self::definition(), 'SCOPED_POLICY_SCHEMA_UPDATE_FAILED');
    }
    /** Pure definition for schema reconciliation; no migration or marker write. */
    public static function definition(): string
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql="CREATE TABLE ".Tables::scoped_capability_policies()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shop_key varchar(100) NOT NULL,
  workflow_key varchar(100) NOT NULL,
  capability varchar(64) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  policy_version int unsigned NOT NULL,
  policy_hash char(64) NOT NULL,
  previous_policy_hash char(64) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scope_version (shop_key,workflow_key,capability,policy_version),
  UNIQUE KEY policy_hash (policy_hash),
  KEY scope_latest (shop_key(40),workflow_key(40),capability(32),created_at)
) $charset;";
        return $sql;
    }
}
