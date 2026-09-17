<?php

declare(strict_types=1);

namespace DigiForge\Database;

/**
 * Additive business/store/program ownership registry for POD workflows.
 * This intentionally leaves the physical supplier catalog global/shared.
 */
final class BusinessScopeSchema
{
    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE " . Tables::businesses() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  business_key varchar(100) NOT NULL,
  display_name varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'INACTIVE',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY business_key (business_key),
  KEY status_updated (status,updated_at)
) $charset;",
            "CREATE TABLE " . Tables::stores() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  business_id bigint(20) unsigned NOT NULL,
  store_key varchar(100) NOT NULL,
  display_name varchar(191) NOT NULL DEFAULT '',
  channel varchar(32) NOT NULL DEFAULT 'etsy',
  status varchar(20) NOT NULL DEFAULT 'INACTIVE',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY business_store (business_id,store_key),
  KEY business_status (business_id,status)
) $charset;",
            "CREATE TABLE " . Tables::product_programs() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  business_id bigint(20) unsigned NOT NULL,
  store_id bigint(20) unsigned NOT NULL,
  program_key varchar(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'INACTIVE',
  config longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY store_program (store_id,program_key),
  KEY business_program (business_id,program_key),
  KEY status_updated (status,updated_at)
) $charset;",
            "CREATE TABLE " . Tables::pod_business_mappings() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  business_id bigint(20) unsigned NOT NULL,
  store_id bigint(20) unsigned NOT NULL,
  product_program_id bigint(20) unsigned NOT NULL,
  product_version_id bigint(20) unsigned NOT NULL,
  provider_mapping_id bigint(20) unsigned NOT NULL,
  state varchar(32) NOT NULL DEFAULT 'DRAFT',
  idempotency_key varchar(191) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scope_product_mapping (business_id,store_id,product_program_id,product_version_id,provider_mapping_id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY scope_state (business_id,store_id,product_program_id,state),
  KEY provider_mapping (provider_mapping_id)
) $charset;",
        ];
    }
}
