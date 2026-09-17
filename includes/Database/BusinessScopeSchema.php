<?php

declare(strict_types=1);

namespace DigiForge\Database;

/** Additive business/store/program ownership registry for POD workflows. */
final class BusinessScopeSchema
{
    /** @return list<string> */
    public static function statements(string $charset): array
    {
        return [
            "CREATE TABLE ".Tables::businesses()." (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  business_key varchar(100) NOT NULL,\n  display_name varchar(191) NOT NULL DEFAULT '',\n  status varchar(20) NOT NULL DEFAULT 'INACTIVE',\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY business_key (business_key),\n  KEY status_updated (status,updated_at)\n) $charset;",
            "CREATE TABLE ".Tables::stores()." (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  business_id bigint(20) unsigned NOT NULL,\n  store_key varchar(100) NOT NULL,\n  display_name varchar(191) NOT NULL DEFAULT '',\n  channel varchar(32) NOT NULL DEFAULT 'etsy',\n  status varchar(20) NOT NULL DEFAULT 'INACTIVE',\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY business_store (business_id,store_key),\n  KEY business_status (business_id,status)\n) $charset;",
            "CREATE TABLE ".Tables::product_programs()." (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  business_id bigint(20) unsigned NOT NULL,\n  store_id bigint(20) unsigned NOT NULL,\n  program_key varchar(64) NOT NULL,\n  status varchar(20) NOT NULL DEFAULT 'INACTIVE',\n  config longtext NULL,\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY store_program (store_id,program_key),\n  KEY business_program (business_id,program_key),\n  KEY status_updated (status,updated_at)\n) $charset;",
            "CREATE TABLE ".Tables::pod_business_mappings()." (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  business_id bigint(20) unsigned NOT NULL,\n  store_id bigint(20) unsigned NOT NULL,\n  product_program_id bigint(20) unsigned NOT NULL,\n  product_version_id bigint(20) unsigned NOT NULL,\n  provider_mapping_id bigint(20) unsigned NOT NULL,\n  state varchar(32) NOT NULL DEFAULT 'DRAFT',\n  request_fingerprint char(64) NOT NULL,\n  idempotency_key varchar(191) NULL DEFAULT NULL,\n  created_by bigint(20) unsigned NOT NULL DEFAULT 0,\n  created_at datetime NOT NULL,\n  updated_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  UNIQUE KEY product_provider_owner (product_version_id,provider_mapping_id),\n  UNIQUE KEY idempotency_key (idempotency_key),\n  KEY request_fingerprint (request_fingerprint),\n  KEY scope_state (business_id,store_id,product_program_id,state),\n  KEY provider_mapping (provider_mapping_id)\n) $charset;",
        ];
    }
}
