<?php

declare(strict_types=1);

namespace DigiForge\Database;

/**
 * Batch 7 additive POD/provider and personalization schema installer.
 *
 * No network/provider execution is introduced here. Existing v9 installs receive
 * only Batch 7 tables; fresh/older installs may pre-create them before Migrator
 * establishes the remaining current schema.
 */
final class PodSchema
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;

        $currentVersion = (int) get_option('digiforge_db_schema_version', 0);
        if ($currentVersion >= 10) {
            // Reconcile additive POD tables introduced after schema 10 without
            // rewinding the current database version.
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            foreach (self::statements($charset) as $statement) {
                $wpdb->last_error = '';
                dbDelta($statement);
                if ($wpdb->last_error !== '') {
                    update_option('digiforge_last_migration_failure', [
                        'error_code' => 'POD_SCHEMA_UPDATE_FAILED',
                        'occurred_at' => current_time('mysql', true),
                    ], false);
                    return false;
                }
            }
            if (!self::backfillOutcomeClaims()) { return false; }
            if (!self::backfillOutcomeClaims()) { return false; }
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
                    'error_code' => 'POD_SCHEMA_UPDATE_FAILED',
                    'occurred_at' => current_time('mysql', true),
                ], false);
                return false;
            }
        }

        if ($currentVersion === 9) {
            update_option('digiforge_db_schema_version', 10, false);
        }

        self::grantCapability();
        return true;
    }

    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::pod_execution_outcomes() . " (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  authorization_hash char(64) NOT NULL,\n  outcome_type varchar(16) NOT NULL,\n  outcome_hash char(64) NOT NULL,\n  created_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY authorization_hash (authorization_hash),\n  UNIQUE KEY outcome_hash (outcome_hash),\n  KEY outcome_type (outcome_type)\n) $charset;",
            "CREATE TABLE " . Tables::pod_execution_unknowns() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action varchar(100) NOT NULL,
  evidence_hash char(64) NOT NULL,
  authorization_hash char(64) NOT NULL,
  nonce_hash char(64) NOT NULL,
  failure_category varchar(64) NOT NULL DEFAULT '',
  failure_code varchar(100) NOT NULL DEFAULT '',
  request_fingerprint char(64) NOT NULL,
  reconciliation_identity text NOT NULL,
  executed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  recorded_at datetime NOT NULL,
  unknown_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY authorization_hash (authorization_hash),
  UNIQUE KEY unknown_hash (unknown_hash),
  KEY nonce_hash (nonce_hash),
  KEY action_recorded_at (action,recorded_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_execution_failures() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action varchar(100) NOT NULL,
  evidence_hash char(64) NOT NULL,
  authorization_hash char(64) NOT NULL,
  nonce_hash char(64) NOT NULL,
  failure_category varchar(64) NOT NULL DEFAULT '',
  failure_code varchar(100) NOT NULL DEFAULT '',
  executed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  recorded_at datetime NOT NULL,
  failure_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY authorization_hash (authorization_hash),
  UNIQUE KEY failure_hash (failure_hash),
  KEY nonce_hash (nonce_hash),
  KEY action_recorded_at (action,recorded_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_execution_receipts() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  action varchar(100) NOT NULL,
  evidence_hash char(64) NOT NULL,
  authorization_hash char(64) NOT NULL,
  nonce_hash char(64) NOT NULL,
  external_reference varchar(191) NOT NULL DEFAULT '',
  executed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  executed_at datetime NOT NULL,
  receipt_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY authorization_hash (authorization_hash),
  UNIQUE KEY receipt_hash (receipt_hash),
  KEY nonce_hash (nonce_hash),
  KEY action_executed_at (action,executed_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_execution_nonces() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  nonce_hash char(64) NOT NULL,
  authorization_hash char(64) NOT NULL,
  consumed_by bigint(20) unsigned NOT NULL,
  consumed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY nonce_hash (nonce_hash),
  KEY authorization_hash (authorization_hash),
  KEY consumed_at (consumed_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_production_templates() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_id varchar(100) NOT NULL,
  template_version int unsigned NOT NULL,
  supplier varchar(32) NOT NULL,
  provider_blueprint_id bigint unsigned NOT NULL,
  provider_id bigint unsigned NOT NULL,
  variant_ids longtext NOT NULL,
  print_areas longtext NOT NULL,
  personalization_pipeline varchar(32) NOT NULL,
  personalization_engine varchar(64) NOT NULL,
  template_status varchar(32) NOT NULL DEFAULT 'DRAFT',
  fingerprint char(64) NOT NULL,
  created_by bigint unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY template_version (template_id,template_version),
  UNIQUE KEY fingerprint (fingerprint),
  KEY supplier_blueprint (supplier,provider_blueprint_id,provider_id),
  KEY template_status (template_status)
) $charset;",
            "CREATE TABLE " . Tables::pod_catalog() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(32) NOT NULL,
  environment varchar(20) NOT NULL,
  provider_product_key varchar(191) NOT NULL,
  provider_variant_key varchar(191) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  variant_label varchar(191) NOT NULL DEFAULT '',
  attributes longtext NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  base_cost decimal(12,4) NOT NULL DEFAULT 0,
  shipping_profile longtext NULL,
  availability_state varchar(32) NOT NULL DEFAULT 'UNKNOWN',
  source_revision varchar(100) NOT NULL DEFAULT '',
  observed_at datetime NULL,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_environment_variant (provider,environment,provider_product_key,provider_variant_key),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY provider_state (provider,environment,state)
) $charset;",
            "CREATE TABLE " . Tables::pod_mappings() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_version_id bigint(20) unsigned NOT NULL,
  production_plan_id bigint(20) unsigned NOT NULL,
  provider varchar(32) NOT NULL,
  environment varchar(20) NOT NULL,
  provider_product_key varchar(191) NOT NULL,
  provider_variant_key varchar(191) NOT NULL DEFAULT '',
  mapping_version varchar(64) NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  notes longtext NULL,
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY mapping_identity (product_version_id,production_plan_id,provider,environment,provider_product_key,provider_variant_key,mapping_version),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY product_state (product_version_id,state),
  KEY provider_environment (provider,environment)
) $charset;",
            "CREATE TABLE " . Tables::pod_print_areas() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider_mapping_id bigint(20) unsigned NOT NULL,
  asset_spec_id bigint(20) unsigned NOT NULL,
  area_key varchar(100) NOT NULL,
  placement varchar(100) NOT NULL DEFAULT '',
  width_value decimal(12,4) NOT NULL DEFAULT 0,
  height_value decimal(12,4) NOT NULL DEFAULT 0,
  unit varchar(16) NOT NULL DEFAULT 'px',
  dpi_target smallint unsigned NOT NULL DEFAULT 0,
  bleed_metadata longtext NULL,
  safe_area_metadata longtext NULL,
  accepted_formats longtext NULL,
  background_policy varchar(64) NOT NULL DEFAULT '',
  orientation_policy varchar(64) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'DRAFT',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY mapping_area (provider_mapping_id,area_key),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY mapping_state (provider_mapping_id,state)
) $charset;",
            "CREATE TABLE " . Tables::personalization_schemas() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_version_id bigint(20) unsigned NOT NULL,
  schema_key varchar(100) NOT NULL,
  version_label varchar(64) NOT NULL,
  field_definitions longtext NOT NULL,
  normalization_rules longtext NULL,
  preview_instructions longtext NULL,
  policy_constraints longtext NULL,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY product_schema_version (product_version_id,schema_key,version_label),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY product_state (product_version_id,state)
) $charset;",
            "CREATE TABLE " . Tables::personalization_bindings() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  personalization_schema_id bigint(20) unsigned NOT NULL,
  asset_spec_id bigint(20) unsigned NOT NULL,
  field_key varchar(100) NOT NULL,
  binding_type varchar(32) NOT NULL DEFAULT 'asset_field',
  instructions longtext NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY schema_asset_field (personalization_schema_id,asset_spec_id,field_key),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY schema_asset (personalization_schema_id,asset_spec_id)
) $charset;",
            "CREATE TABLE " . Tables::pod_provider_intents() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider_mapping_id bigint(20) unsigned NOT NULL DEFAULT 0,
  personalization_schema_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(32) NOT NULL,
  environment varchar(20) NOT NULL,
  intent_type varchar(64) NOT NULL,
  input_payload longtext NULL,
  state varchar(32) NOT NULL DEFAULT 'BLOCKED',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY provider_state (provider,environment,state),
  KEY mapping_state (provider_mapping_id,state)
) $charset;",
            "CREATE TABLE " . Tables::pod_cost_snapshots() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider_mapping_id bigint(20) unsigned NOT NULL,
  currency char(3) NOT NULL,
  base_production_cost decimal(12,4) NOT NULL DEFAULT 0,
  shipping_estimate decimal(12,4) NOT NULL DEFAULT 0,
  fee_metadata longtext NULL,
  observed_at datetime NULL,
  source_type varchar(32) NOT NULL DEFAULT 'manual',
  state varchar(20) NOT NULL DEFAULT 'DRAFT',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY mapping_state (provider_mapping_id,state),
  KEY observed_at (observed_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_readiness_reviews() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider_mapping_id bigint(20) unsigned NOT NULL,
  readiness longtext NOT NULL,
  readiness_hash char(64) NOT NULL,
  decision varchar(20) NOT NULL DEFAULT 'PENDING',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY mapping_decision (provider_mapping_id,decision),
  KEY readiness_hash (readiness_hash)
) $charset;",
        ];
    }


    private static function backfillOutcomeClaims(): bool
    {
        global $wpdb;
        $target=Tables::pod_execution_outcomes();
        $sources=[
            [Tables::pod_execution_receipts(),'SUCCEEDED','receipt_hash'],
            [Tables::pod_execution_failures(),'FAILED','failure_hash'],
            [Tables::pod_execution_unknowns(),'UNKNOWN','unknown_hash'],
        ];
        $seen=[];
        foreach($sources as [$table,$type,$hashColumn]){
            $rows=$wpdb->get_results("SELECT authorization_hash, {$hashColumn} AS outcome_hash FROM {$table}",ARRAY_A);
            if(!is_array($rows))return false;
            foreach($rows as $row){
                $auth=(string)($row['authorization_hash']??'');$hash=(string)($row['outcome_hash']??'');
                if(isset($seen[$auth])&&($seen[$auth]['type']!==$type||$seen[$auth]['hash']!==$hash)){
                    update_option('digiforge_last_migration_failure',['error_code'=>'POD_OUTCOME_BACKFILL_CONFLICT','occurred_at'=>current_time('mysql',true)],false);return false;
                }
                $seen[$auth]=['type'=>$type,'hash'=>$hash];
            }
        }
        foreach($seen as $auth=>$outcome){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT outcome_type,outcome_hash FROM {$target} WHERE authorization_hash=%s LIMIT 1",$auth),ARRAY_A);
            if(is_array($existing)){
                if((string)$existing['outcome_type']!==$outcome['type']||(string)$existing['outcome_hash']!==$outcome['hash'])return false;
                continue;
            }
            if($wpdb->insert($target,['authorization_hash'=>$auth,'outcome_type'=>$outcome['type'],'outcome_hash'=>$outcome['hash'],'created_at'=>current_time('mysql',true)])===false)return false;
        }
        return true;
    }

    private static function grantCapability(): void
    {
        if ($role = get_role('administrator')) {
            $role->add_cap('manage_digiforge_pod');
        }
    }
}
