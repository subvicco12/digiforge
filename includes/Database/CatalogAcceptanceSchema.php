<?php
declare(strict_types=1);
namespace DigiForge\Database;

/** Additive component contract; no lower marker or silent repair of incomplete tables. */
final class CatalogAcceptanceSchema
{
    public const VERSION = 24;
    public static function migrateIfNeeded(): bool
    {
        return ComponentSchema::ensure(Tables::catalog_acceptance_evidence(), self::VERSION, self::definition(), 'CATALOG_ACCEPTANCE_SCHEMA_UPDATE_FAILED');
    }
    /** Pure definition for schema reconciliation; no migration or marker write. */
    public static function definition(): string
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql="CREATE TABLE ".Tables::catalog_acceptance_evidence()." (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  catalog_version_id bigint(20) unsigned NOT NULL,
  parent_version_id bigint(20) unsigned NOT NULL,
  source_sha256 char(64) NOT NULL,
  fingerprint char(64) NOT NULL,
  decision varchar(16) NOT NULL,
  reviewer_id bigint(20) unsigned NOT NULL,
  acknowledgement_hash char(64) NOT NULL,
  reviewed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY catalog_version (catalog_version_id),
  UNIQUE KEY acknowledgement_hash (acknowledgement_hash),
  KEY parent_version (parent_version_id),
  KEY reviewed_at (reviewed_at)
) $charset;";
        return $sql;
    }
}
