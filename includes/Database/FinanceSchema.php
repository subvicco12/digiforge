<?php

declare(strict_types=1);

namespace DigiForge\Database;

final class FinanceSchema
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;
        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 13) {
            self::grantCapability();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (self::statements($charset) as $statement) {
            $wpdb->last_error = '';
            dbDelta($statement);
            if ($wpdb->last_error !== '') {
                update_option('digiforge_last_migration_failure', [
                    'error_code' => 'FINANCE_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        if ($currentVersion === 12) {
            update_option('digiforge_db_schema_version', 13, false);
        }
        self::grantCapability();
        return true;
    }

    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::finance_ledger() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  source_type varchar(64) NOT NULL,
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entry_type varchar(64) NOT NULL,
  currency char(3) NOT NULL,
  amount decimal(14,4) NOT NULL DEFAULT 0,
  base_currency char(3) NOT NULL DEFAULT '',
  base_amount decimal(14,4) NULL,
  effective_date date NOT NULL,
  metadata longtext NULL,
  canonical_hash char(64) NOT NULL,
  reconciliation_state varchar(32) NOT NULL DEFAULT 'UNRECONCILED',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY source_ref (source_type,source_id),
  KEY environment_date (environment,effective_date),
  KEY entry_type (entry_type),
  KEY reconciliation_state (reconciliation_state)
) $charset;",
            "CREATE TABLE " . Tables::fx_snapshots() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  base_currency char(3) NOT NULL,
  quote_currency char(3) NOT NULL,
  rate decimal(20,10) NOT NULL,
  as_of_at datetime NOT NULL,
  source_metadata longtext NULL,
  canonical_hash char(64) NOT NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY snapshot_hash (canonical_hash),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY pair_as_of (base_currency,quote_currency,as_of_at),
  KEY environment (environment)
) $charset;",
            "CREATE TABLE " . Tables::tax_classifications() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  source_type varchar(64) NOT NULL,
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  jurisdiction varchar(191) NOT NULL DEFAULT '',
  tax_category varchar(64) NOT NULL DEFAULT '',
  classification varchar(32) NOT NULL DEFAULT 'REVIEW_REQUIRED',
  currency char(3) NOT NULL,
  amount_basis decimal(14,4) NOT NULL DEFAULT 0,
  evidence longtext NULL,
  review_status varchar(32) NOT NULL DEFAULT 'UNREVIEWED',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY source_ref (source_type,source_id),
  KEY environment_review (environment,review_status)
) $charset;",
            "CREATE TABLE " . Tables::finance_periods() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  period_start date NOT NULL,
  period_end date NOT NULL,
  base_currency char(3) NOT NULL,
  metrics longtext NOT NULL,
  metrics_hash char(64) NOT NULL,
  calculation_version varchar(64) NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'OPEN',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY period_version (environment,period_start,period_end,calculation_version),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY environment_state (environment,state)
) $charset;",
            "CREATE TABLE " . Tables::analytics_snapshots() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  dimension_type varchar(64) NOT NULL,
  dimension_id bigint(20) unsigned NOT NULL DEFAULT 0,
  period_start date NOT NULL,
  period_end date NOT NULL,
  metrics longtext NOT NULL,
  metrics_hash char(64) NOT NULL,
  freshness_metadata longtext NULL,
  calculation_version varchar(64) NOT NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY snapshot_identity (environment,dimension_type,dimension_id,period_start,period_end,calculation_version),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY dimension_ref (dimension_type,dimension_id),
  KEY environment_period (environment,period_start,period_end)
) $charset;",
            "CREATE TABLE " . Tables::operational_alerts() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  alert_type varchar(64) NOT NULL,
  source_type varchar(64) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  severity varchar(20) NOT NULL DEFAULT 'INFO',
  evidence longtext NOT NULL,
  evidence_hash char(64) NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'OPEN',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY alert_hash (evidence_hash),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY environment_state (environment,state),
  KEY source_ref (source_type,source_id)
) $charset;",
            "CREATE TABLE " . Tables::finance_intents() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  environment varchar(20) NOT NULL,
  source_type varchar(64) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  intent_type varchar(64) NOT NULL,
  input_payload longtext NULL,
  state varchar(32) NOT NULL DEFAULT 'BLOCKED',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY environment_state (environment,state),
  KEY source_ref (source_type,source_id)
) $charset;",
        ];
    }

    private static function grantCapability(): void
    {
        if ($role = get_role('administrator')) {
            $role->add_cap('manage_digiforge_finance');
        }
    }
}
